<?php

namespace App\Services\TrainedAgent;

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\Quota;
use App\Enums\TrainedAgent\HireSource;
use App\Enums\TrainedAgent\HireStatus;
use App\Events\TrainedAgentHireUpdated;
use App\Jobs\TrainedAgent\FulfillTrainedAgentHire;
use App\Models\AiHubProviderCredential;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentHire;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The trained-agent catalog: what a tenant can hire, what it costs them, and
 * the fork that turns a platform blueprint into one of their own agents.
 *
 * Two things are worth knowing before reading further.
 *
 * 1. A hire is a COPY, not a subscription to something the platform keeps
 *    running. Once forked, the agent is an ordinary AiHubAgent the tenant can
 *    edit, retrain or delete; later edits to the blueprint never reach it. That
 *    is why the purchase is one-off, and why nothing here has a renewal path.
 *
 * 2. The model bill stays with the tenant. The fork runs on one of their own
 *    provider credentials, exactly like a hand-made agent, so the price on a
 *    blueprint buys the training work — not the tokens.
 */
class TrainedAgentService
{
    public function __construct(
        private readonly SubscriptionGate $gate,
        private readonly BillingService $billing,
        private readonly AiAgentHubService $hub,
        private readonly AiAgentHubTenantService $hubTenant,
    ) {}

    /* ------------------------------------------------------------------
     | Catalog
     * ------------------------------------------------------------------ */

    /**
     * Blueprints the tenant may hire, in catalog order.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TrainedAgentBlueprint>
     */
    public function catalog()
    {
        return TrainedAgentBlueprint::query()
            ->available()
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * What the tenant's plan gives them, and what they have spent.
     *
     * `included_limit` null means the plan says nothing about trained agents,
     * which is zero slots — see SubscriptionGate::canHireIncludedTrainedAgent
     * for why absence is not unlimited here.
     */
    public function usageSummary(Tenant $tenant): array
    {
        $limit = $this->gate->quota($tenant, Quota::IncludedTrainedAgents->value);
        $used = $this->gate->trainedAgentsUsed($tenant);

        return [
            'included_limit' => $limit,
            'included_used' => $used,
            'included_remaining' => max(0, ($limit ?? 0) - $used),
            'can_hire_included' => $this->gate->canHireIncludedTrainedAgent($tenant),
            'owned' => TrainedAgentHire::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', [HireStatus::Provisioning->value, HireStatus::Active->value])
                ->count(),
        ];
    }

    /**
     * The tenant's hires, newest first. `pending_payment` rows are included:
     * unlike an API Way checkout, an unpaid hire is a card the tenant can come
     * back and finish (or abandon) rather than a half-provisioned asset.
     */
    public function hires(Tenant $tenant)
    {
        return TrainedAgentHire::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', HireStatus::Cancelled->value)
            ->with(['blueprint.category', 'agent'])
            ->orderByDesc('id')
            ->get();
    }

    /* ------------------------------------------------------------------
     | Hiring
     * ------------------------------------------------------------------ */

    /**
     * Take a blueprint. Free while the plan's included slots last, a one-off
     * Pix charge after that.
     *
     * The caller does not choose which: letting the client ask for the free
     * path would make "included" a request parameter. The allowance is read
     * here, from the gate, and the returned hire says what actually happened.
     *
     * @return array{hire: TrainedAgentHire, invoice: ?Invoice}
     */
    public function hire(
        Tenant $tenant,
        TrainedAgentBlueprint $blueprint,
        int $providerCredentialId,
        ?string $agentName = null,
        ?string $payerEmail = null,
    ): array {
        $credential = $this->resolveCredential($tenant, $providerCredentialId);

        $useIncluded = $this->gate->canHireIncludedTrainedAgent($tenant);

        if (! $useIncluded && $blueprint->isFree()) {
            // A zero-price blueprint outside the allowance has no way to be
            // sold, so refusing beats creating a R$ 0,00 charge nobody can pay.
            throw ValidationException::withMessages([
                'blueprint' => __('This agent is only available through your plan, and your included agents are used up. Upgrade your plan to hire it.'),
            ]);
        }

        $hire = DB::transaction(function () use ($tenant, $blueprint, $credential, $agentName, $useIncluded) {
            /** @var TrainedAgentHire $hire */
            $hire = TrainedAgentHire::create([
                'tenant_id' => $tenant->id,
                'trained_agent_blueprint_id' => $blueprint->id,
                'ai_hub_provider_credential_id' => $credential->id,
                'external_ref' => 'pending',
                'source' => $useIncluded ? HireSource::Included : HireSource::Purchased,
                'status' => $useIncluded ? HireStatus::Provisioning : HireStatus::PendingPayment,
                'agent_name' => $agentName ?: $blueprint->name,
                'price_cents' => $useIncluded ? 0 : $blueprint->price_cents,
                'currency' => $blueprint->currency ?: 'BRL',
                'blueprint_snapshot' => $blueprint->snapshot(),
            ]);

            // Needs the id, so it is a second write rather than a default.
            $hire->update(['external_ref' => 'pingly-ta-'.$hire->id]);

            return $hire;
        });

        if ($useIncluded) {
            FulfillTrainedAgentHire::dispatch($hire->id);
            TrainedAgentHireUpdated::dispatch($hire->fresh());

            return ['hire' => $hire->fresh(), 'invoice' => null];
        }

        try {
            $invoice = $this->billing->createTrainedAgentPixInvoice($hire, $payerEmail);
        } catch (\Throwable $e) {
            // No charge exists, so leaving the row behind would only show the
            // tenant a card they can neither pay nor explain.
            $hire->delete();

            throw $e;
        }

        TrainedAgentHireUpdated::dispatch($hire->fresh());

        return ['hire' => $hire->fresh(), 'invoice' => $invoice];
    }

    /**
     * Give up on an unpaid hire. Deletes the row (and voids its open charge)
     * rather than marking it cancelled — an abandoned checkout is not history
     * worth keeping, and a lingering card reads like something went wrong.
     */
    public function abandonPending(TrainedAgentHire $hire): bool
    {
        if ($hire->status !== HireStatus::PendingPayment) {
            return false;
        }

        // cancelInvoice() re-reads settled charges, so a Pix paid seconds ago
        // is applied instead of voided — which moves this row to provisioning.
        $hire->invoices()
            ->where('status', InvoiceStatus::Pending->value)
            ->get()
            ->each(fn (Invoice $invoice) => $this->billing->cancelInvoice($invoice));

        $hire->refresh();

        if ($hire->status !== HireStatus::PendingPayment) {
            return false;
        }

        // No broadcast: the row is about to stop existing, and a queued event
        // carrying a deleted model cannot deserialize. Invoices keep their
        // audit trail (the FK nulls out on delete).
        $hire->delete();

        return true;
    }

    /** A paid Pix charge landed: queue the fork. */
    public function handleInvoicePaid(Invoice $invoice): void
    {
        $hire = $invoice->trainedAgentHire;

        if (! $hire) {
            Log::warning('Paid trained agent invoice with no hire', ['invoice_id' => $invoice->id]);

            return;
        }

        if ($hire->status === HireStatus::PendingPayment) {
            $hire->update(['status' => HireStatus::Provisioning]);
        }

        FulfillTrainedAgentHire::dispatch($hire->id);
        TrainedAgentHireUpdated::dispatch($hire->fresh());
    }

    /**
     * Put a failed fork back in flight. Safe to call repeatedly: fulfil()
     * resumes from whatever it already managed to copy.
     */
    public function retry(TrainedAgentHire $hire): void
    {
        if ($hire->status !== HireStatus::Failed) {
            return;
        }

        $meta = $hire->meta ?? [];
        unset($meta['failure']);

        $hire->update(['status' => HireStatus::Provisioning, 'meta' => $meta ?: null]);

        FulfillTrainedAgentHire::dispatch($hire->id);
        TrainedAgentHireUpdated::dispatch($hire->fresh());
    }

    /* ------------------------------------------------------------------
     | Fulfilment (the fork)
     * ------------------------------------------------------------------ */

    /**
     * Copy the blueprint into the tenant's workspace.
     *
     * Resumable by design. This is a dozen or more HTTP calls to the hub, and
     * a failure halfway leaves a real agent already created — re-running must
     * continue rather than mint a second one, so progress is written to
     * `meta.progress` after every step and every step is skipped if the
     * counter says it is already done.
     *
     * Throws on failure so the queue retries; `FulfillTrainedAgentHire::failed`
     * is what finally marks the hire failed.
     */
    public function fulfill(TrainedAgentHire $hire): void
    {
        $snapshot = $hire->blueprint_snapshot ?? [];

        if (! $snapshot) {
            throw new \RuntimeException("Trained agent hire {$hire->id} has no blueprint snapshot");
        }

        $tenant = $hire->tenant;
        $aiHubTenant = $this->hub->createTenant($tenant);
        $this->hub->createApiKey($aiHubTenant);
        $aiHubTenant = $aiHubTenant->fresh();

        $progress = $hire->meta['progress'] ?? [];

        $agent = $hire->agent;

        if (! $agent) {
            $credential = $hire->providerCredential;

            if (! $credential || ! $credential->hub_provider_credential_id) {
                throw new \RuntimeException("Trained agent hire {$hire->id} has no usable provider credential");
            }

            $agent = $this->hubTenant->createAgent($aiHubTenant, [
                'name' => $hire->agent_name ?: ($snapshot['name'] ?? 'Agent'),
                'description' => $snapshot['tagline'] ?? ($snapshot['category'] ?? null),
                'providerCredentialId' => $credential->hub_provider_credential_id,
                'model' => $snapshot['model'],
                'systemPrompt' => $snapshot['system_prompt'] ?? null,
                'temperature' => $snapshot['temperature'] ?? null,
                'maxTokens' => $snapshot['max_tokens'] ?? null,
                'status' => 'ACTIVE',
                'handoffRules' => $snapshot['handoff_rules'] ?? null,
                // Provenance, not behaviour. Months later this is the only
                // thing that explains where a prompt nobody wrote came from.
                'metadata' => [
                    'source' => 'trained_agent',
                    'blueprintId' => $snapshot['blueprint_id'] ?? null,
                    'blueprintSlug' => $snapshot['slug'] ?? null,
                    'hireId' => $hire->id,
                ],
            ]);

            $hire->update(['ai_hub_agent_id' => $agent->id]);
        }

        if (! empty($snapshot['profile']) && empty($progress['profile'])) {
            $this->hubTenant->setAgentProfile($agent, $this->profilePayload($snapshot['profile']));
            $progress = $this->noteProgress($hire, $progress, 'profile', true);
        }

        $progress = $this->copyList(
            $hire,
            $progress,
            'knowledge',
            $snapshot['knowledge'] ?? [],
            fn (array $item) => $this->hubTenant->createAgentKnowledge($agent, [
                'title' => $item['title'] ?? '',
                'content' => $item['content'] ?? '',
                'tags' => $item['tags'] ?? null,
            ]),
        );

        $progress = $this->copyList(
            $hire,
            $progress,
            'skills',
            $snapshot['skills'] ?? [],
            fn (array $item) => $this->hubTenant->createAgentSkill($agent, [
                'name' => $item['name'] ?? '',
                'description' => $item['description'] ?? null,
                'instructions' => $item['instructions'] ?? null,
            ]),
        );

        $this->copyList(
            $hire,
            $progress,
            'training_examples',
            $snapshot['training_examples'] ?? [],
            fn (array $item) => $this->hubTenant->createAgentTrainingExample($agent, [
                'type' => $item['type'] ?? 'style_example',
                'input' => $item['input'] ?? '',
                'expectedOutput' => $item['expected_output'] ?? ($item['expectedOutput'] ?? ''),
                'notes' => $item['notes'] ?? null,
            ]),
        );

        $meta = $hire->fresh()->meta ?? [];
        unset($meta['needs_refund'], $meta['failure']);

        $hire->update([
            'status' => HireStatus::Active,
            'hired_at' => $hire->hired_at ?? now(),
            'meta' => $meta ?: null,
        ]);

        Log::info('Trained agent hire fulfilled', [
            'hire_id' => $hire->id,
            'tenant_id' => $hire->tenant_id,
            'ai_hub_agent_id' => $hire->ai_hub_agent_id,
        ]);

        TrainedAgentHireUpdated::dispatch($hire->fresh());
    }

    /**
     * Record a fork that ran out of retries.
     *
     * A paid hire additionally gets `meta.needs_refund` — money was captured
     * and nothing was delivered. It is the same flag API Way writes, and it is
     * read the same way: by the Back Office, so a paying customer who heard
     * nothing is a row someone can act on rather than a line in a log file.
     */
    public function markFailed(TrainedAgentHire $hire, string $reason): void
    {
        $meta = $hire->meta ?? [];
        $meta['failure'] = ['reason' => $reason, 'at' => now()->toISOString()];

        if ($hire->source === HireSource::Purchased && $hire->price_cents > 0) {
            $meta['needs_refund'] = true;
        }

        $hire->update(['status' => HireStatus::Failed, 'meta' => $meta]);

        Log::error('Trained agent hire failed', [
            'hire_id' => $hire->id,
            'tenant_id' => $hire->tenant_id,
            'reason' => $reason,
            'needs_refund' => $meta['needs_refund'] ?? false,
        ]);

        TrainedAgentHireUpdated::dispatch($hire->fresh());
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * The credential must belong to the tenant's own AI hub workspace. It is
     * the tenant's token that will pay for every run, so accepting an id from
     * the request without this check would let one workspace spend another's.
     */
    private function resolveCredential(Tenant $tenant, int $credentialId): AiHubProviderCredential
    {
        $aiHubTenant = $tenant->aiHubTenant;

        $credential = $aiHubTenant
            ? AiHubProviderCredential::query()
                ->where('ai_hub_tenant_id', $aiHubTenant->id)
                ->find($credentialId)
            : null;

        if (! $credential) {
            throw ValidationException::withMessages([
                'provider_credential_id' => __('Select one of your own AI provider credentials.'),
            ]);
        }

        return $credential;
    }

    /** The hub's profile payload is camelCase; the blueprint stores it as given. */
    private function profilePayload(array $profile): array
    {
        return array_filter([
            'language' => $profile['language'] ?? null,
            'tone' => $profile['tone'] ?? null,
            'responseStyle' => $profile['response_style'] ?? ($profile['responseStyle'] ?? null),
            'instructions' => $profile['instructions'] ?? null,
            'limits' => $profile['limits'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Copy a list, remembering how far it got. `$progress[$key]` is a count,
     * so a resumed run starts at the first item that was never sent.
     */
    private function copyList(TrainedAgentHire $hire, array $progress, string $key, array $items, callable $create): array
    {
        $done = (int) ($progress[$key] ?? 0);

        foreach (array_slice(array_values($items), $done) as $offset => $item) {
            if (! is_array($item)) {
                continue;
            }

            $create($item);
            $progress = $this->noteProgress($hire, $progress, $key, $done + $offset + 1);
        }

        return $progress;
    }

    private function noteProgress(TrainedAgentHire $hire, array $progress, string $key, mixed $value): array
    {
        $progress[$key] = $value;

        $meta = $hire->meta ?? [];
        $meta['progress'] = $progress;
        $hire->update(['meta' => $meta]);

        return $progress;
    }

    /** Purpose used for every trained-agent charge. */
    public static function invoicePurpose(): InvoicePurpose
    {
        return InvoicePurpose::TrainedAgentPurchase;
    }
}
