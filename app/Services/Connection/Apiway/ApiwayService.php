<?php

namespace App\Services\Connection\Apiway;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Market\MarketCapability;
use App\Enums\Notification\NotificationType;
use App\Events\ApiwaySubscriptionUpdated;
use App\Events\ConnectionUpdated;
use App\Exceptions\ApiwayPartnerException;
use App\Exceptions\ConnectionException;
use App\Jobs\ProvisionApiwaySubscription;
use App\Jobs\RenewApiwaySubscription;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Credits\CreditService;
use App\Services\Market\MarketCapabilities;
use App\Support\Money;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the API Way instance lifecycle: provisioning at ProxyBR,
 * renewals and cancels. ProxyBR has NO grace period, so anything past
 * expires_at is revoked permanently on their side.
 *
 * ⚠️ Instances are not a product any more (Nov 2026). A customer never buys,
 * picks or renews one: connecting a "WhatsApp via API Way" connection gets it
 * an instance — a spare one the workspace already has, or a new one made for
 * it — and that instance renews for free while it serves a connection and the
 * workspace's plan is usable. The WhatsApp connection quota, enforced where
 * connections are created, is the only limit. What the customer sees is a
 * number and a QR code; instance ids, subscriptions and tokens are ours.
 *
 * The paid paths (unit purchases, balance-charged renewals) are gone. Rows they
 * left behind (`source = unit`, open Pix invoices) are still read, renewed for
 * free and cancelled like the rest — nothing here charges anybody any more.
 */
class ApiwayService
{
    private const CATALOG_CACHE_KEY = 'apiway:partner-catalog';

    private const CATALOG_CACHE_TTL = 300; // seconds

    public function __construct(
        protected ApiwayPartnerClient $partner,
        protected BillingService $billing,
        protected SubscriptionGate $gate,
        protected BillingNotifier $notifier,
        protected CreditService $credits,
    ) {}

    /** The ledger reference for the first charge of a purchase. */
    public static function purchaseReference(ApiwaySubscription $row): string
    {
        return "apiway:buy:{$row->id}";
    }

    // --- Catalog -----------------------------------------------------------

    /**
     * The instance's core API token: stored locally, fetched once from the
     * partner console when missing — the only instance operation ProxyBR
     * still mediates; everything else goes straight to the core with it.
     */
    public function instanceCoreToken(ApiwayInstance $instance): string
    {
        if ($instance->token) {
            return $instance->token;
        }

        $data = $this->partner->instanceToken($instance->provider_instance_id);
        $token = trim((string) ($data['token'] ?? ''));

        if ($token === '') {
            throw new ApiwayPartnerException('A instância não possui token de API disponível.', errorCode: 'token_missing', httpStatus: 422);
        }

        $instance->update(['token' => $token]);

        return $token;
    }

    /** Partner catalog with a short cache (price tiers, locations, settings). */
    public function catalog(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CATALOG_CACHE_KEY);
        }

        return Cache::remember(self::CATALOG_CACHE_KEY, self::CATALOG_CACHE_TTL, fn () => $this->partner->plans());
    }

    // --- Instances for connections ----------------------------------------

    /**
     * The instance a "WhatsApp via API Way" connection should run on: a spare
     * one the workspace already has, or a new one made for it. Free either way.
     *
     * Spare first, always. An instance whose connection was deleted was logged
     * out on the way and stays live until its period ends (it is not renewed —
     * see apiway:renew), so handing it to the next connection costs nothing,
     * where a new one is another subscription at ProxyBR. An instance an earlier
     * attempt made for this very connection is preferred among the spares, so
     * a retried connect lands on the number that was being prepared for it.
     *
     * Serialised per workspace: two wizards connecting at the same moment must
     * not both see "no spare" and each order a new instance.
     *
     * @return ApiwayInstance|null null while a new instance is still being made
     *                             at ProxyBR — the caller says "preparing your
     *                             number" and asks again.
     *
     * @throws ConnectionException when the workspace cannot have one at all
     */
    public function instanceFor(Connection $connection): ?ApiwayInstance
    {
        $tenant = $connection->tenant;

        try {
            return Cache::lock("apiway:instance-for:{$tenant->id}", 90)
                ->block(15, fn () => $this->findOrProvisionInstance($tenant, $connection));
        } catch (LockTimeoutException) {
            // Another request is preparing one right now; asking again shortly
            // finds it, which is exactly what "preparing" tells the caller to do.
            return null;
        }
    }

    private function findOrProvisionInstance(Tenant $tenant, Connection $connection): ?ApiwayInstance
    {
        $spare = $tenant->apiwayInstances()
            ->available()
            ->with('subscription')
            ->orderBy('id')
            ->get()
            ->sortByDesc(fn (ApiwayInstance $instance) => $this->madeFor($instance->subscription, $connection))
            ->first();

        if ($spare) {
            return $spare;
        }

        // An earlier attempt for this connection that ProxyBR has not finished.
        // Filtered in PHP: provisioning rows are few, and a JSON path predicate
        // reads differently on MySQL and SQLite.
        $preparing = $tenant->apiwaySubscriptions()
            ->where('status', ApiwaySubscriptionStatus::Provisioning->value)
            ->doesntHave('instances')
            ->get()
            ->contains(fn (ApiwaySubscription $row) => $this->madeFor($row, $connection));

        if ($preparing) {
            return null;
        }

        $this->assertCanProvision($tenant);

        $row = $this->createLocalSubscription($tenant, [
            // "plan_included" is the free kind of row; the name predates the
            // instances being free for everybody on a plan.
            'source' => ApiwaySubscriptionSource::PlanIncluded,
            'status' => ApiwaySubscriptionStatus::Provisioning,
            // One instance per subscription, always: every row the Back Office
            // lists is then exactly one number, and cancelling one never takes
            // another workspace's number down with it.
            'quantity' => 1,
            'cycle' => 'mensal',
            'location_code' => $this->defaultLocationCode(),
            'meta' => ['for_connection_id' => $connection->id],
        ]);

        try {
            $this->provisionOrQueue($row, 'instance');
        } catch (ValidationException) {
            throw new ConnectionException(
                'Não foi possível preparar um número de WhatsApp agora. Tente novamente em alguns minutos.',
                502,
            );
        }

        return $tenant->apiwayInstances()
            ->available()
            ->where('apiway_subscription_id', $row->id)
            ->first();
    }

    private function madeFor(?ApiwaySubscription $row, Connection $connection): bool
    {
        return (int) ($row?->meta['for_connection_id'] ?? 0) === $connection->id;
    }

    /**
     * Whether this workspace may have a new instance made for it.
     *
     * Two rules, and no money: the country has to carry API Way (ProxyBR runs
     * Brazilian infrastructure), and the plan has to be usable — the instance
     * is free because the plan is paid, so a workspace without one gets none.
     */
    private function assertCanProvision(Tenant $tenant): void
    {
        if (! MarketCapabilities::allowsFor($tenant, MarketCapability::ApiwayInstances->value)) {
            throw new ConnectionException('O WhatsApp via API Way não está disponível no seu país.', 422);
        }

        if (! $tenant->currentSubscription?->isUsable()) {
            throw new ConnectionException('Sua assinatura precisa estar ativa para conectar um número de WhatsApp.', 403);
        }
    }

    /**
     * Try a stuck or failed free instance again — the Back Office's button.
     *
     * Only free rows: a legacy paid row that failed was refunded when it did,
     * and delivering it now would hand out what was already given back.
     */
    public function retryProvision(ApiwaySubscription $row): ApiwaySubscription
    {
        if ($row->provider_subscription_id || $row->source !== ApiwaySubscriptionSource::PlanIncluded) {
            throw ValidationException::withMessages([
                'subscription' => 'Only a free instance that never finished provisioning can be retried.',
            ]);
        }

        if ($row->status === ApiwaySubscriptionStatus::Failed) {
            $meta = $row->meta ?? [];
            unset($meta['failure'], $meta['capacity_hold']);
            $row->update(['status' => ApiwaySubscriptionStatus::Provisioning, 'meta' => $meta]);
        }

        if ($row->status !== ApiwaySubscriptionStatus::Provisioning) {
            throw ValidationException::withMessages([
                'subscription' => 'Only a free instance that never finished provisioning can be retried.',
            ]);
        }

        ProvisionApiwaySubscription::dispatch($row->id);

        return $row->fresh();
    }

    /**
     * Provision now if we can, hand it to the queue if we cannot.
     *
     * Doing it inline first is what lets a normal purchase come back already
     * active, so the customer sees an instance rather than a spinner. Every way
     * that can fail ends with the work queued or already parked — the one thing
     * that must never happen is a paid row nobody comes back to.
     */
    protected function provisionOrQueue(ApiwaySubscription $row, string $context): void
    {
        try {
            $this->provision($row);
        } catch (ApiwayPartnerException $e) {
            if (! $e->isCapacityHold() && ! $e->isRetriable()) {
                // provision() already marked it failed, refunded and notified.
                throw ValidationException::withMessages([$context => $e->getMessage()]);
            }

            // A hold is already parked by provision() and retried hourly by
            // apiway:sync — queuing here only buys an immediate second refusal.
            if (! $e->isCapacityHold()) {
                ProvisionApiwaySubscription::dispatch($row->id);
            }
        } catch (\Throwable $e) {
            Log::warning('Apiway provisioning deferred to queue', [
                'apiway_subscription_id' => $row->id,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            ProvisionApiwaySubscription::dispatch($row->id);
        }
    }

    // --- Provisioning ------------------------------------------------------

    /**
     * Create the subscription at ProxyBR. Idempotent: replays of the same
     * external_ref return the original subscription, so a retry after a crash
     * can never double-provision.
     */
    public function provision(ApiwaySubscription $row): void
    {
        $row->refresh();

        if ($row->status === ApiwaySubscriptionStatus::Active && $row->provider_subscription_id) {
            return;
        }

        if ($row->status !== ApiwaySubscriptionStatus::Provisioning) {
            Log::warning('Apiway provision skipped: unexpected status', [
                'apiway_subscription_id' => $row->id,
                'status' => $row->status->value,
            ]);

            return;
        }

        try {
            $result = $this->partner->createSubscription(
                $row->external_ref,
                $this->externalUser($row->tenant),
                $row->quantity,
                $row->location_code,
                $row->cycle,
            );
        } catch (ApiwayPartnerException $e) {
            if ($e->isCapacityHold()) {
                $this->markCapacityHold($row, $e);
            } elseif (! $e->isRetriable()) {
                $this->markProvisionFailed($row, $e);
            }

            throw $e;
        }

        $data = $result['data'];

        DB::transaction(function () use ($row, $data) {
            $meta = $row->meta ?? [];
            unset($meta['capacity_hold']);

            $row->update([
                'meta' => $meta,
                'provider_subscription_id' => $data['id'] ?? null,
                'status' => ApiwaySubscriptionStatus::Active,
                'unit_price_cents' => $this->toCents($data['unit_price'] ?? ($row->unit_price_cents / 100)),
                'total_price_cents' => $this->toCents($data['total_price'] ?? ($row->total_price_cents / 100)),
                'expires_at' => isset($data['expires_at']) ? \Illuminate\Support\Carbon::parse($data['expires_at']) : null,
                'last_synced_at' => now(),
            ]);

            foreach (($data['instances'] ?? []) as $instance) {
                if (empty($instance['id'])) {
                    continue;
                }

                ApiwayInstance::firstOrCreate(
                    ['provider_instance_id' => $instance['id']],
                    [
                        'tenant_id' => $row->tenant_id,
                        'apiway_subscription_id' => $row->id,
                        'name' => $instance['name'] ?? null,
                        'ip_address' => $instance['ip_address'] ?? null,
                        'status' => $instance['status'] ?? null,
                    ],
                );
            }
        });

        $this->gate->forget($row->tenant);
        ApiwaySubscriptionUpdated::dispatch($row->fresh());

        // A free instance is made because somebody is connecting a number and
        // is watching the QR code appear — a message about "your instance" is
        // telling them about plumbing they were never shown.
        if ($row->source === ApiwaySubscriptionSource::Unit) {
            $this->notifier->notifyTenant(NotificationType::ApiwayPurchaseActivated, $row->tenant, [
                'quantity' => $row->quantity,
            ]);
        }
    }

    /**
     * ProxyBR is at its platform ceiling. Hold the purchase instead of failing
     * it: the row stays `provisioning`, the customer keeps their place, and
     * apiway:sync retries hourly until an operator raises the cap.
     *
     * Bounded, though — a hold that never resolves is a customer who paid and
     * heard nothing, which is worse than an honest refund. Past the window this
     * degrades into the ordinary permanent failure.
     */
    protected function markCapacityHold(ApiwaySubscription $row, ApiwayPartnerException $e): void
    {
        $hold = $row->meta['capacity_hold'] ?? [];
        $since = isset($hold['since']) ? \Illuminate\Support\Carbon::parse($hold['since']) : now();

        if ($since->addHours($this->capacityHoldHours())->isPast()) {
            Log::error('Apiway capacity hold exhausted — giving up', [
                'apiway_subscription_id' => $row->id,
                'held_since' => $hold['since'] ?? null,
                'attempts' => $hold['attempts'] ?? 0,
            ]);

            $this->markProvisionFailed($row, $e);

            return;
        }

        $meta = $row->meta ?? [];
        $meta['capacity_hold'] = [
            'code' => $e->getErrorCode(),
            'message' => $e->getMessage(),
            'since' => ($hold['since'] ?? now()->toISOString()),
            'last_attempt_at' => now()->toISOString(),
            'attempts' => ((int) ($hold['attempts'] ?? 0)) + 1,
        ];

        $row->update(['meta' => $meta]);

        // Platform-level, not tenant-level: nothing the customer does fixes a
        // cap on our side, and ApiwayProvisionFailed promises a call back.
        Log::warning('Apiway provisioning held: ProxyBR at platform capacity', [
            'apiway_subscription_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'quantity' => $row->quantity,
            'code' => $e->getErrorCode(),
            'attempts' => $meta['capacity_hold']['attempts'],
            'paid' => $row->source === ApiwaySubscriptionSource::Unit,
        ]);

        ApiwaySubscriptionUpdated::dispatch($row->fresh());
    }

    protected function capacityHoldHours(): int
    {
        return max(1, (int) config('services.apiway.capacity_hold_hours', 24));
    }

    /**
     * How long a `provisioning` row may sit with nothing happening to it before
     * apiway:sync assumes nobody is coming back for it.
     *
     * Comfortably longer than the provision job's own retry ladder (five
     * attempts over about eight minutes), so this only ever picks up work that
     * has genuinely been dropped rather than racing a job still trying.
     */
    private const STALLED_PROVISION_MINUTES = 20;

    /**
     * Re-attempt purchases that are paid for but not provisioned. Driven by
     * apiway:sync rather than the job's own retries: five attempts across eight
     * minutes buy nothing against a ceiling only a human raises.
     *
     * Two kinds of row, and the second matters more now than it used to.
     * Capacity holds are parked deliberately by markCapacityHold(). Stalled rows
     * are the accidents — a worker killed between the charge and the dispatch, a
     * job that exhausted its retries on network errors and left `failed()` to
     * write a log line nobody reads. Both mean money has been taken and no
     * instance exists, which since the balance became the payment method is a
     * debit sitting in a customer's statement with nothing to show for it.
     */
    public function retryPendingProvisions(?Tenant $tenant = null): int
    {
        $rows = ApiwaySubscription::query()
            ->where('status', ApiwaySubscriptionStatus::Provisioning->value)
            ->whereNull('provider_subscription_id')
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->get()
            // Filtered here, not in SQL: provisioning rows are transient and
            // few, and a JSON path predicate would differ per DB driver.
            ->filter(fn (ApiwaySubscription $row) => isset($row->meta['capacity_hold'])
                || $row->updated_at?->lt(now()->subMinutes(self::STALLED_PROVISION_MINUTES)));

        foreach ($rows as $row) {
            if (! isset($row->meta['capacity_hold'])) {
                Log::warning('Apiway provisioning stalled, re-queuing', [
                    'apiway_subscription_id' => $row->id,
                    'tenant_id' => $row->tenant_id,
                    'stalled_since' => $row->updated_at?->toISOString(),
                ]);
            }

            ProvisionApiwaySubscription::dispatch($row->id);
        }

        return $rows->count();
    }

    protected function markProvisionFailed(ApiwaySubscription $row, ApiwayPartnerException $e): void
    {
        $meta = $row->meta ?? [];
        $meta['failure'] = ['code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'at' => now()->toISOString()];
        $refunded = null;

        if ($row->source === ApiwaySubscriptionSource::Unit) {
            // Paid from the balance: give it straight back. This is the whole
            // reason charging before provisioning is defensible here — the money
            // never left the platform, so undoing it is a ledger row rather than
            // a Pix refund somebody has to remember to make.
            $refunded = $this->credits->reverseByReference(
                $row->tenant,
                self::purchaseReference($row),
                'Devolução — instância API Way não ativada',
                ['apiway_subscription_id' => $row->id, 'reason' => $e->getErrorCode()],
            );

            if ($refunded === null) {
                // No wallet charge behind this row — a purchase from before the
                // balance, paid by Pix or card. Those still need a human, and the
                // Back Office button that finds them reads this flag.
                $meta['needs_refund'] = true;
            } else {
                $meta['refunded_to_balance_at'] = now()->toISOString();
                $meta['refunded_cents'] = $refunded->amount_cents;
            }
        }

        $row->update(['status' => ApiwaySubscriptionStatus::Failed, 'meta' => $meta]);

        Log::error('Apiway provisioning failed permanently', [
            'apiway_subscription_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'code' => $e->getErrorCode(),
            'refunded_to_balance' => $refunded !== null,
            'needs_refund' => $meta['needs_refund'] ?? false,
        ]);

        ApiwaySubscriptionUpdated::dispatch($row->fresh());

        // A free instance that failed is reported where it was asked for — the
        // connection wizard says it could not prepare a number. Only a paid
        // row has something else to tell: where the money went.
        if ($row->source !== ApiwaySubscriptionSource::Unit) {
            return;
        }

        // Two different messages because two different things are true. Telling
        // someone "our team will contact you" when their money is already back
        // in their balance sends them to support for a problem that is over.
        $refunded === null
            ? $this->notifier->notifyTenant(NotificationType::ApiwayProvisionFailed, $row->tenant)
            : $this->notifier->notifyTenant(NotificationType::ApiwayProvisionRefunded, $row->tenant, [
                'amount' => Money::format($refunded->amount_cents, $refunded->currency ?: $row->tenant?->currency()),
            ]);
    }

    /**
     * Give back a renewal charge whose renewal never happened at ProxyBR.
     *
     * Called from the job's `failed()` hook, which is the only place that knows
     * the attempt is over rather than between retries. The reference is passed
     * in rather than recomputed because `expires_at` may have moved underneath
     * us by then — and a reversal that misses its debit silently keeps the money.
     */
    public function reverseRenewalCharge(ApiwaySubscription $row, string $reference): void
    {
        $reversal = $this->credits->reverseByReference(
            $row->tenant,
            $reference,
            'Devolução — renovação API Way não concluída',
            ['apiway_subscription_id' => $row->id],
        );

        if ($reversal !== null) {
            Log::warning('Apiway renewal charge reversed after the renew failed', [
                'apiway_subscription_id' => $row->id,
                'reference' => $reference,
                'cents' => $reversal->amount_cents,
            ]);
        }
    }

    // --- Payment hooks (called from BillingService, no partner HTTP here) ---

    /** An apiway invoice settled: move the flow forward via queued jobs only. */
    public function handleApiwayInvoicePaid(Invoice $invoice): void
    {
        $row = $invoice->apiwaySubscription;

        if (! $row) {
            Log::warning('Paid apiway invoice with no apiway subscription', ['invoice_id' => $invoice->id]);

            return;
        }

        if ($invoice->purpose === InvoicePurpose::ApiwayPurchase) {
            if ($row->status === ApiwaySubscriptionStatus::PendingPayment) {
                $row->update(['status' => ApiwaySubscriptionStatus::Provisioning]);
            }

            ProvisionApiwaySubscription::dispatch($row->id);
        }

        if ($invoice->purpose === InvoicePurpose::ApiwayRenewal) {
            RenewApiwaySubscription::dispatch($row->id, 'pingly-renew-inv-'.$invoice->id);
        }

        ApiwaySubscriptionUpdated::dispatch($row->fresh());
    }

    // --- Renewal / cancel / sync ------------------------------------------

    /**
     * Renew at ProxyBR. Price is re-quoted by them at call time; the local row
     * mirrors the values they recorded. Idempotent via the caller-chosen key.
     */
    public function renew(ApiwaySubscription $row, string $idempotencyKey, ?string $cycle = null): void
    {
        if (! $row->provider_subscription_id) {
            throw new ApiwayPartnerException('Subscription was never provisioned.', 'invalid_state', 422);
        }

        try {
            $data = $this->partner->renewSubscription($row->provider_subscription_id, $idempotencyKey, $cycle);
        } catch (ApiwayPartnerException $e) {
            if ($e->getErrorCode() === 'invalid_state') {
                // Expired/cancelled at ProxyBR while we were charging — resync.
                $this->syncStatuses($row->tenant);
            }

            throw $e;
        }

        $subscription = $data['subscription'] ?? [];
        $renewal = $data['renewal'] ?? [];

        $row->update([
            'status' => ApiwaySubscriptionStatus::Active,
            'cycle' => $this->normalizeCycle($renewal['cycle'] ?? $row->cycle),
            'unit_price_cents' => $this->toCents($renewal['unit_price'] ?? ($row->unit_price_cents / 100)),
            'total_price_cents' => $this->toCents($renewal['total_price'] ?? ($row->total_price_cents / 100)),
            'expires_at' => isset($subscription['expires_at'])
                ? \Illuminate\Support\Carbon::parse($subscription['expires_at'])
                : $row->expires_at,
            'renewal_reminder_sent_at' => null,
            'last_synced_at' => now(),
        ]);

        $this->gate->forget($row->tenant);
        ApiwaySubscriptionUpdated::dispatch($row->fresh());
    }

    /**
     * Drop an unpaid purchase entirely: void its open charges (killing the Pix
     * QR locally) and DELETE the local row —
     * abandoned checkouts must never linger in the instance list.
     *
     * @return bool False when the purchase settled meanwhile (caller → 409):
     *              a paid row moves on to provisioning and must survive.
     */
    public function abandonPendingPurchase(ApiwaySubscription $row): bool
    {
        if ($row->status !== ApiwaySubscriptionStatus::PendingPayment) {
            return false;
        }

        // cancelInvoice() re-reads settled charges — a Pix paid seconds ago is
        // applied instead of voided, which flips this row to provisioning.
        $this->voidOpenInvoices($row);
        $row->refresh();

        if ($row->status !== ApiwaySubscriptionStatus::PendingPayment) {
            return false;
        }

        // Invoices keep their audit trail (FK nulls out on delete).
        $row->delete();

        return true;
    }

    /**
     * Cancel = permanent revoke at ProxyBR (instances deleted, proxies
     * released). Linked connections are deactivated and released.
     */
    public function cancel(ApiwaySubscription $row): ApiwaySubscription
    {
        if ($row->status->isTerminal()) {
            return $row;
        }

        if ($row->provider_subscription_id) {
            try {
                $this->partner->cancelSubscription($row->provider_subscription_id);
            } catch (ApiwayPartnerException $e) {
                // Already terminal / gone at ProxyBR is fine; hub failures must
                // surface so the user can retry (resources would stay alive).
                if (! in_array($e->getErrorCode(), ['not_found', 'invalid_state'], true)) {
                    throw $e;
                }
            }
        }

        DB::transaction(function () use ($row) {
            $row->update(['status' => ApiwaySubscriptionStatus::Cancelled]);
            $this->releaseInstances($row);
        });

        $this->voidOpenInvoices($row);
        $this->gate->forget($row->tenant);
        ApiwaySubscriptionUpdated::dispatch($row->fresh());

        return $row->fresh();
    }

    /**
     * Local expiry pass + best-effort reconciliation with ProxyBR. Mirrors
     * their no-grace hourly revoke: anything past expires_at is gone for good.
     *
     * @return array{expired: int, synced: int, retried: int}
     */
    public function syncStatuses(?Tenant $tenant = null): array
    {
        // Hygiene: abandoned checkouts (never paid) are dropped after a day —
        // their Pix QR has long expired and the FE never shows them anyway.
        ApiwaySubscription::query()
            ->where('status', ApiwaySubscriptionStatus::PendingPayment->value)
            ->where('created_at', '<', now()->subDay())
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->get()
            ->each(fn (ApiwaySubscription $stale) => $this->abandonPendingPurchase($stale));

        $expired = 0;

        $query = ApiwaySubscription::query()
            ->whereIn('status', [
                ApiwaySubscriptionStatus::Provisioning->value,
                ApiwaySubscriptionStatus::Active->value,
                ApiwaySubscriptionStatus::Suspended->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id));

        foreach ($query->get() as $row) {
            $this->markExpired($row);
            $expired++;
        }

        return [
            'expired' => $expired,
            'retried' => $this->retryPendingProvisions($tenant),
            'synced' => $this->reconcileWithPartner($tenant),
        ];
    }

    public function markExpired(ApiwaySubscription $row): void
    {
        DB::transaction(function () use ($row) {
            $row->update(['status' => ApiwaySubscriptionStatus::Expired]);
            $this->releaseInstances($row);
        });

        $this->voidOpenInvoices($row);
        $this->gate->forget($row->tenant);
        ApiwaySubscriptionUpdated::dispatch($row->fresh());

        // A free instance only lapses when nothing was using it, or when the
        // plan behind it stopped — and the plan has its own messages for that.
        if ($row->source === ApiwaySubscriptionSource::Unit) {
            $this->notifier->notifyTenant(NotificationType::ApiwayExpired, $row->tenant, [
                'quantity' => $row->quantity,
            ]);
        }
    }

    /** Pull partner-side state for live rows (status, expiry, instance status). */
    protected function reconcileWithPartner(?Tenant $tenant = null): int
    {
        if (! $this->partner->isConfigured()) {
            return 0;
        }

        $synced = 0;
        $page = 1;

        do {
            try {
                $response = $this->partner->listSubscriptions(array_filter([
                    'external_user_id' => $tenant ? $this->externalUserId($tenant) : null,
                    'page' => $page,
                    'per_page' => 50,
                ]));
            } catch (\Throwable $e) {
                Log::warning('Apiway partner reconciliation failed', ['error' => $e->getMessage()]);

                return $synced;
            }

            $rows = $response['data'] ?? [];

            foreach ($rows as $remote) {
                if ($this->applyRemoteState($remote)) {
                    $synced++;
                }
            }

            $lastPage = $response['meta']['last_page'] ?? $page;
            $page++;
        } while ($page <= min($lastPage, 40) && $rows !== []);

        return $synced;
    }

    /** @return bool Whether a local row was found and updated. */
    protected function applyRemoteState(array $remote): bool
    {
        if (empty($remote['id'])) {
            return false;
        }

        $row = ApiwaySubscription::where('provider_subscription_id', $remote['id'])->first();

        if (! $row) {
            return false;
        }

        $remoteStatus = $this->mapProviderStatus($remote['status'] ?? null);

        if ($remoteStatus && ! $row->status->isTerminal() && $remoteStatus !== $row->status) {
            if ($remoteStatus->isTerminal()) {
                DB::transaction(function () use ($row, $remoteStatus) {
                    $row->update(['status' => $remoteStatus]);
                    $this->releaseInstances($row);
                });
                $this->voidOpenInvoices($row);
                $this->gate->forget($row->tenant);
                ApiwaySubscriptionUpdated::dispatch($row->fresh());
            } else {
                $row->update(['status' => $remoteStatus]);
            }
        }

        $updates = ['last_synced_at' => now()];

        if (! empty($remote['expires_at'])) {
            $updates['expires_at'] = \Illuminate\Support\Carbon::parse($remote['expires_at']);
        }

        $row->update($updates);

        foreach (($remote['instances'] ?? []) as $instance) {
            if (empty($instance['id'])) {
                continue;
            }

            ApiwayInstance::where('provider_instance_id', $instance['id'])->update([
                'status' => $instance['status'] ?? null,
                'ip_address' => $instance['ip_address'] ?? null,
            ]);
        }

        return true;
    }

    // --- Helpers -----------------------------------------------------------

    public function externalUser(Tenant $tenant): array
    {
        $owner = $tenant->user;

        return array_filter([
            'id' => $this->externalUserId($tenant),
            'email' => $owner?->email ?? "tenant-{$tenant->id}@pingly.invalid",
            'name' => $owner?->name,
            'whatsapp' => $owner?->whatsapp_number ? preg_replace('/\D+/', '', $owner->whatsapp_number) : null,
        ]);
    }

    public function externalUserId(Tenant $tenant): string
    {
        return 'tenant-'.$tenant->id;
    }

    /**
     * Detach every connection this subscription was serving.
     *
     * Marking the connection inactive is not enough: it keeps the instance id,
     * the API token and the last QR code, so the connect screen goes on
     * offering a code that can never pair and every retry dies inside the core
     * with "not connected". WhatsappApiwayChannel::releaseCredentials() moves
     * that aside and leaves a record of which instance went away and why —
     * which is what lets the wizard say so and offer a replacement instead of
     * looking broken.
     */
    protected function releaseInstances(ApiwaySubscription $row): void
    {
        $reason = $row->status === ApiwaySubscriptionStatus::Cancelled
            ? 'subscription_cancelled'
            : 'subscription_expired';

        foreach ($row->instances()->whereNotNull('connection_id')->with('connection')->get() as $instance) {
            if ($connection = $instance->connection) {
                WhatsappApiwayChannel::releaseCredentials($connection, $reason);
                $connection->update(['status' => ConnectionStatus::Inactive]);
                broadcast(new ConnectionUpdated($connection->fresh()));
            }

            $instance->update(['connection_id' => null]);
        }
    }

    protected function voidOpenInvoices(ApiwaySubscription $row): void
    {
        foreach ($row->invoices()->pending()->get() as $invoice) {
            try {
                $this->billing->cancelInvoice($invoice);
            } catch (\Throwable $e) {
                Log::warning('Failed to void apiway invoice', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }
    }

    protected function createLocalSubscription(Tenant $tenant, array $attributes): ApiwaySubscription
    {
        return DB::transaction(function () use ($tenant, $attributes) {
            $row = $tenant->apiwaySubscriptions()->create(array_merge([
                'external_ref' => 'tmp-'.Str::uuid(),
                'unit_price_cents' => 0,
                'total_price_cents' => 0,
            ], $attributes));

            // Stable, human-traceable idempotency key ("pingly-apw-{id}") — the
            // uuid placeholder only exists because the id isn't known pre-insert.
            $row->update(['external_ref' => 'pingly-apw-'.$row->id]);

            return $row;
        });
    }

    protected function defaultLocationCode(): string
    {
        try {
            foreach (($this->catalog()['locations'] ?? []) as $location) {
                if (($location['active'] ?? false) && ! empty($location['public_code'])) {
                    return $location['public_code'];
                }
            }
        } catch (\Throwable) {
            // Catalog unreachable — fall through to the historical default.
        }

        return 'br';
    }

    public function normalizeCycle(?string $cycle): string
    {
        return in_array($cycle, ['anual', 'annual', 'yearly'], true) ? 'anual' : 'mensal';
    }

    protected function mpFrequencyForCycle(string $cycle): array
    {
        return $cycle === 'anual'
            ? ['frequency' => 12, 'frequency_type' => 'months']
            : ['frequency' => 1, 'frequency_type' => 'months'];
    }

    protected function mapProviderStatus(?string $status): ?ApiwaySubscriptionStatus
    {
        return match ($status) {
            'provisioning' => ApiwaySubscriptionStatus::Provisioning,
            'active' => ApiwaySubscriptionStatus::Active,
            'suspended' => ApiwaySubscriptionStatus::Suspended,
            'expired' => ApiwaySubscriptionStatus::Expired,
            'cancelled' => ApiwaySubscriptionStatus::Cancelled,
            default => null,
        };
    }

    protected function toCents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
