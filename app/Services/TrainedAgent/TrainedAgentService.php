<?php

namespace App\Services\TrainedAgent;

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Credit\CreditTransactionType;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\Quota;
use App\Enums\TrainedAgent\HireSource;
use App\Enums\TrainedAgent\HireStatus;
use App\Events\TrainedAgentHireUpdated;
use App\Jobs\TrainedAgent\FulfillTrainedAgentHire;
use App\Models\AiHubProviderCredential;
use App\Services\AiTokens\AiTokenRentalService;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentHire;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use App\Services\Credits\CreditService;
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
        private readonly CreditService $credits,
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
     * The ledger reference for a hire's charge, for the attempt it is on.
     *
     * Numbered because a failed fork is refunded and a retry is charged again.
     * A single reference per hire would make the second charge look like a
     * duplicate of the first and be silently dropped — the tenant would get the
     * agent without paying for it.
     */
    public static function chargeReference(TrainedAgentHire $hire): string
    {
        return "trained-agent:{$hire->id}:".(int) ($hire->meta['charge_attempt'] ?? 1);
    }

    /**
     * Take a blueprint. Free while the plan's included slots last, charged to
     * the prepaid balance after that.
     *
     * The caller does not choose which: letting the client ask for the free
     * path would make "included" a request parameter. The allowance is read
     * here, from the gate, and the returned hire says what actually happened.
     *
     * Both paths now end in the same place — a `provisioning` row with the fork
     * queued — because the balance settles in this request. There is no waiting
     * for a payment any more, which is what `pending_payment` was for.
     *
     * @throws InsufficientCreditException when a paid hire outruns the balance
     */
    public function hire(
        Tenant $tenant,
        TrainedAgentBlueprint $blueprint,
        int $providerCredentialId,
        ?string $agentName = null,
    ): TrainedAgentHire {
        $credential = $this->resolveCredential($tenant, $providerCredentialId);

        $useIncluded = $this->gate->canHireIncludedTrainedAgent($tenant);

        if (! $useIncluded && $blueprint->isFree()) {
            // A zero-price blueprint outside the allowance has no way to be
            // sold, so refusing beats creating a R$ 0,00 charge nobody can pay.
            throw ValidationException::withMessages([
                'blueprint' => __('This agent is only available through your plan, and your included agents are used up. Upgrade your plan to hire it.'),
            ]);
        }

        $priceCents = $useIncluded ? 0 : $blueprint->price_cents;

        // Checked before the row exists so an unaffordable attempt leaves
        // nothing behind. The debit re-checks under its own lock.
        if ($priceCents > 0 && ! $this->credits->canAfford($tenant, $priceCents)) {
            throw new InsufficientCreditException($this->credits->balanceCents($tenant), $priceCents);
        }

        $hire = DB::transaction(function () use ($tenant, $blueprint, $credential, $agentName, $useIncluded, $priceCents) {
            /** @var TrainedAgentHire $hire */
            $hire = TrainedAgentHire::create([
                'tenant_id' => $tenant->id,
                'trained_agent_blueprint_id' => $blueprint->id,
                'ai_hub_provider_credential_id' => $credential->id,
                'external_ref' => 'pending',
                'source' => $useIncluded ? HireSource::Included : HireSource::Purchased,
                'status' => HireStatus::Provisioning,
                'agent_name' => $agentName ?: $blueprint->name,
                'price_cents' => $priceCents,
                'currency' => $blueprint->currency ?: 'BRL',
                'blueprint_snapshot' => $blueprint->snapshot(),
            ]);

            // Needs the id, so it is a second write rather than a default.
            $hire->update(['external_ref' => 'pingly-ta-'.$hire->id]);

            return $hire;
        });

        if ($priceCents > 0) {
            try {
                $this->credits->debit(
                    $tenant,
                    $priceCents,
                    CreditTransactionType::Purchase,
                    self::chargeReference($hire),
                    'Agente treinado — '.($hire->agent_name ?: $blueprint->name),
                    ['trained_agent_hire_id' => $hire->id, 'blueprint_id' => $blueprint->id],
                );
            } catch (InsufficientCreditException $e) {
                // Lost the race with a concurrent purchase. Nothing was charged,
                // so the row must not survive looking like an agent on its way.
                $hire->delete();

                throw $e;
            }
        }

        FulfillTrainedAgentHire::dispatch($hire->id);
        TrainedAgentHireUpdated::dispatch($hire->fresh());

        return $hire->fresh();
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
     *
     * A paid hire is charged again, because failing gave the money back. That
     * pairing is the point: at no moment does the platform hold money for an
     * agent that does not exist, and at no moment does a tenant get one without
     * paying. In the ordinary case the refund is sitting in their balance and
     * the retry is invisible to them.
     *
     * @throws InsufficientCreditException when the balance no longer covers it
     */
    public function retry(TrainedAgentHire $hire): void
    {
        if ($hire->status !== HireStatus::Failed) {
            return;
        }

        $meta = $hire->meta ?? [];
        unset($meta['failure']);

        if ($hire->source === HireSource::Purchased && $hire->price_cents > 0
            && ! empty($meta['refunded_to_balance_at'])) {
            // Only when the previous attempt was actually refunded. A hire that
            // failed before the balance existed was never given back, so
            // charging now would be the second time they paid.
            $meta['charge_attempt'] = (int) ($meta['charge_attempt'] ?? 1) + 1;
            unset($meta['refunded_to_balance_at'], $meta['refunded_cents']);

            $hire->fill(['meta' => $meta]);

            $this->credits->debit(
                $hire->tenant,
                $hire->price_cents,
                CreditTransactionType::Purchase,
                self::chargeReference($hire),
                'Agente treinado — '.($hire->agent_name ?: 'nova tentativa'),
                ['trained_agent_hire_id' => $hire->id, 'attempt' => $meta['charge_attempt']],
            );
        }

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
     * A paid hire gets its charge back the moment we give up — the money never
     * left the platform, so returning it is a ledger row rather than a Pix
     * refund somebody has to remember to make. `meta.needs_refund` is only
     * written when there is no wallet charge to reverse, which means a hire
     * bought before the balance: those still need the Back Office button.
     *
     * The slot is not held either way. A failed fork delivers nothing, and
     * `HireStatus::Failed` is deliberately outside `consumesAllowance()` — but
     * the row stays so the tenant can retry it, which is why the reversal has
     * to be idempotent: a retry that fails again must not pay twice.
     */
    public function markFailed(TrainedAgentHire $hire, string $reason): void
    {
        $meta = $hire->meta ?? [];
        $meta['failure'] = ['reason' => $reason, 'at' => now()->toISOString()];
        $refunded = null;

        if ($hire->source === HireSource::Purchased && $hire->price_cents > 0) {
            $refunded = $this->credits->reverseByReference(
                $hire->tenant,
                self::chargeReference($hire),
                'Devolução — agente treinado não entregue',
                ['trained_agent_hire_id' => $hire->id, 'reason' => $reason],
            );

            if ($refunded !== null) {
                $meta['refunded_to_balance_at'] = now()->toISOString();
                $meta['refunded_cents'] = $refunded->amount_cents;
            } elseif (empty($meta['refunded_to_balance_at'])) {
                // Nothing to reverse and nothing reversed before: a hire paid
                // outside the wallet, by Pix, before the balance existed. Those
                // still need a human, and the Back Office finds them by this.
                //
                // The `elseif` is not defensive noise: markFailed() can run
                // twice for one attempt, and the second reversal is refused as a
                // duplicate — reading that as "never refunded" would raise a
                // refund flag on money already given back.
                $meta['needs_refund'] = true;
            }
        }

        $hire->update(['status' => HireStatus::Failed, 'meta' => $meta]);

        Log::error('Trained agent hire failed', [
            'hire_id' => $hire->id,
            'tenant_id' => $hire->tenant_id,
            'reason' => $reason,
            'refunded_to_balance' => $refunded !== null,
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

        // A rented credential is repaired before the fork uses it — the fork is
        // a queued job, so a hub record that has gone missing would otherwise
        // fail somewhere the customer only sees as a hire stuck in provisioning.
        // See AiTokenRentalService::ensureUsable.
        return $credential->isRented()
            ? app(AiTokenRentalService::class)->ensureUsable($credential)
            : $credential;
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
