<?php

namespace App\Services\Connection\Apiway;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Notification\NotificationType;
use App\Events\ApiwaySubscriptionUpdated;
use App\Events\ConnectionUpdated;
use App\Exceptions\ApiwayPartnerException;
use App\Jobs\ProvisionApiwaySubscription;
use App\Jobs\RenewApiwaySubscription;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\BillingService;
use App\Services\Billing\MercadoPago\MercadoPagoConfig;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates the API Way instance lifecycle: purchase (included in the plan
 * or unit at catalog price), provisioning at ProxyBR, renewals and cancels.
 * ProxyBR never charges — every payment is collected here first, then mirrored
 * to the partner API. ProxyBR has NO grace period, so anything past expires_at
 * is revoked permanently on their side.
 */
class ApiwayService
{
    /** Included-instance quota key on plan `quotas` JSON. */
    public const INCLUDED_QUOTA_KEY = 'included_instances';

    private const CATALOG_CACHE_KEY = 'apiway:partner-catalog';

    private const CATALOG_CACHE_TTL = 300; // seconds

    public function __construct(
        protected ApiwayPartnerClient $partner,
        protected BillingService $billing,
        protected SubscriptionGate $gate,
        protected BillingNotifier $notifier,
    ) {}

    // --- Catalog -----------------------------------------------------------

    /** Partner catalog with a short cache (price tiers, locations, settings). */
    public function catalog(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CATALOG_CACHE_KEY);
        }

        return Cache::remember(self::CATALOG_CACHE_KEY, self::CATALOG_CACHE_TTL, fn () => $this->partner->plans());
    }

    public function usageSummary(Tenant $tenant): array
    {
        $includedQuota = $this->gate->quota($tenant, self::INCLUDED_QUOTA_KEY) ?? 0;

        $includedUsed = (int) $tenant->apiwaySubscriptions()
            ->where('source', ApiwaySubscriptionSource::PlanIncluded->value)
            ->whereIn('status', [
                ApiwaySubscriptionStatus::Provisioning->value,
                ApiwaySubscriptionStatus::Active->value,
                ApiwaySubscriptionStatus::Suspended->value,
            ])
            ->sum('quantity');

        return [
            'included_quota' => $includedQuota,
            'included_used' => $includedUsed,
            'instances_total' => $tenant->apiwayInstances()
                ->whereHas('subscription', fn ($q) => $q->live())
                ->count(),
            'instances_available' => $tenant->apiwayInstances()->available()->count(),
        ];
    }

    // --- Purchase ----------------------------------------------------------

    /**
     * Provision an instance covered by the plan's `included_instances` quota.
     * No charge — the cost is part of the plan price. Renewed for free while
     * the plan subscription stays usable.
     */
    public function createIncludedInstance(Tenant $tenant): ApiwaySubscription
    {
        if (! $tenant->currentSubscription?->isUsable()) {
            throw ValidationException::withMessages([
                'included' => 'An active plan subscription is required for included instances.',
            ]);
        }

        $usage = $this->usageSummary($tenant);

        if ($usage['included_used'] >= $usage['included_quota']) {
            throw ValidationException::withMessages([
                'included' => 'All instances included in your plan are already in use.',
            ]);
        }

        $row = $this->createLocalSubscription($tenant, [
            'source' => ApiwaySubscriptionSource::PlanIncluded,
            'status' => ApiwaySubscriptionStatus::Provisioning,
            'quantity' => 1,
            'cycle' => $this->cycleForBillingCycle($tenant->currentSubscription->billing_cycle),
            'location_code' => $this->defaultLocationCode(),
        ]);

        try {
            $this->provision($row);
        } catch (ApiwayPartnerException $e) {
            if (! $e->isRetriable()) {
                // provision() already marked the row failed + notified.
                throw ValidationException::withMessages(['included' => $e->getMessage()]);
            }

            ProvisionApiwaySubscription::dispatch($row->id);
        } catch (\Throwable $e) {
            Log::warning('Included apiway provisioning deferred to queue', [
                'apiway_subscription_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            ProvisionApiwaySubscription::dispatch($row->id);
        }

        return $row->fresh();
    }

    /**
     * Start a unit purchase at catalog price. Pix returns a payable invoice;
     * card creates a MercadoPago preapproval (auto-debit every cycle).
     *
     * @return array{subscription: ApiwaySubscription, invoice: ?Invoice, authorized: bool}
     */
    public function startUnitPurchase(
        Tenant $tenant,
        int $quantity,
        string $cycle,
        string $locationCode,
        PaymentMethod $method,
        ?string $cardTokenId = null,
        ?string $payerEmail = null,
    ): array {
        // ProxyBR is the price authority — never trust a client-provided total.
        $quote = $this->partner->quote($quantity, $locationCode, $cycle);

        $row = $this->createLocalSubscription($tenant, [
            'source' => ApiwaySubscriptionSource::Unit,
            'status' => ApiwaySubscriptionStatus::PendingPayment,
            'quantity' => $quantity,
            'cycle' => $this->normalizeCycle($quote['cycle'] ?? $cycle),
            'location_code' => $locationCode,
            'unit_price_cents' => $this->toCents($quote['unit_price'] ?? 0),
            'total_price_cents' => $this->toCents($quote['total_price'] ?? 0),
            'meta' => ['quote' => $quote],
        ]);

        $payerEmail ??= $tenant->user?->email ?? 'no-reply@example.com';

        if ($method === PaymentMethod::Pix) {
            $invoice = $this->billing->createApiwayPixInvoice($row, InvoicePurpose::ApiwayPurchase, $payerEmail);

            return ['subscription' => $row->fresh(), 'invoice' => $invoice, 'authorized' => false];
        }

        return $this->startUnitCardPurchase($row, $cardTokenId, $payerEmail);
    }

    /** Card path: one preapproval per unit purchase → auto-debit renews it. */
    protected function startUnitCardPurchase(ApiwaySubscription $row, ?string $cardTokenId, string $payerEmail): array
    {
        $payload = [
            'reason' => "API Way — {$row->quantity} instância(s)",
            'auto_recurring' => array_merge(
                $this->mpFrequencyForCycle($row->cycle),
                [
                    'transaction_amount' => round($row->total_price_cents / 100, 2),
                    'currency_id' => 'BRL',
                ],
            ),
            'payer_email' => $payerEmail,
            'card_token_id' => $cardTokenId,
            'back_url' => MercadoPagoConfig::backUrl(),
            'status' => 'authorized',
            'external_reference' => "tenant:{$row->tenant_id}:apw:{$row->id}",
        ];

        $response = $this->billing->mercadoPago()->createPreapproval($payload, (string) Str::uuid());

        $row->mp_preapproval_id = $response['id'] ?? null;
        $authorized = ($response['status'] ?? null) === 'authorized';

        if ($authorized) {
            $row->status = ApiwaySubscriptionStatus::Provisioning;
            $row->save();

            $this->recordPaidCardInvoice($row, InvoicePurpose::ApiwayPurchase);
            ProvisionApiwaySubscription::dispatch($row->id);
        } else {
            // Pending authorization — the preapproval webhook completes the flow.
            $row->save();
        }

        ApiwaySubscriptionUpdated::dispatch($row->fresh());

        return ['subscription' => $row->fresh(), 'invoice' => null, 'authorized' => $authorized];
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
            if (! $e->isRetriable()) {
                $this->markProvisionFailed($row, $e);
            }

            throw $e;
        }

        $data = $result['data'];

        DB::transaction(function () use ($row, $data) {
            $row->update([
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
        $this->notifier->notifyTenant(NotificationType::ApiwayPurchaseActivated, $row->tenant, [
            'quantity' => $row->quantity,
        ]);
    }

    protected function markProvisionFailed(ApiwaySubscription $row, ApiwayPartnerException $e): void
    {
        $meta = $row->meta ?? [];
        $meta['failure'] = ['code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'at' => now()->toISOString()];

        // Money was already captured on unit purchases — flag for manual refund.
        if ($row->source === ApiwaySubscriptionSource::Unit) {
            $meta['needs_refund'] = true;
        }

        $row->update(['status' => ApiwaySubscriptionStatus::Failed, 'meta' => $meta]);

        Log::error('Apiway provisioning failed permanently', [
            'apiway_subscription_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'code' => $e->getErrorCode(),
            'needs_refund' => $meta['needs_refund'] ?? false,
        ]);

        ApiwaySubscriptionUpdated::dispatch($row->fresh());
        $this->notifier->notifyTenant(NotificationType::ApiwayProvisionFailed, $row->tenant);
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

    /** Preapproval status change for an apiway unit purchase (webhook fallback). */
    public function handlePreapprovalStatus(ApiwaySubscription $row, ?string $status): void
    {
        if ($status === 'authorized' && $row->status === ApiwaySubscriptionStatus::PendingPayment) {
            $row->update(['status' => ApiwaySubscriptionStatus::Provisioning]);
            $this->recordPaidCardInvoice($row, InvoicePurpose::ApiwayPurchase);
            ProvisionApiwaySubscription::dispatch($row->id);
            ApiwaySubscriptionUpdated::dispatch($row->fresh());

            return;
        }

        if (in_array($status, ['cancelled', 'paused'], true)) {
            // Auto-debit is off; apiway:renew falls back to Pix invoices.
            $meta = $row->meta ?? [];
            $meta['autopay_off'] = true;
            $row->update(['meta' => $meta]);
        }
    }

    /**
     * A recurring auto-debit charge arrived for a unit purchase. The first
     * charge is the purchase itself (backfilled onto the unlinked invoice);
     * later ones are renewals → paid invoice + partner renew job.
     */
    public function recordUnitCardRenewal(ApiwaySubscription $row, ?string $paymentId, ?string $status): void
    {
        if (! in_array($status, ['approved', 'processed'], true)) {
            return;
        }

        if ($paymentId && Invoice::where('mp_payment_id', $paymentId)->exists()) {
            return;
        }

        // Backfill the initial authorization charge onto the purchase invoice
        // recorded without a payment id — that one must not extend the expiry.
        $unlinked = $row->invoices()
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNull('mp_payment_id')
            ->orderBy('id')
            ->first();

        if ($unlinked) {
            $unlinked->update(['mp_payment_id' => $paymentId]);

            return;
        }

        $invoice = Invoice::create([
            'tenant_id' => $row->tenant_id,
            'apiway_subscription_id' => $row->id,
            'purpose' => InvoicePurpose::ApiwayRenewal,
            'status' => InvoiceStatus::Paid,
            'payment_method' => PaymentMethod::Card,
            'amount_cents' => $row->total_price_cents,
            'currency' => 'BRL',
            'period_start' => $row->expires_at,
            'paid_at' => now(),
            'mp_payment_id' => $paymentId,
            'mp_preapproval_id' => $row->mp_preapproval_id,
        ]);

        RenewApiwaySubscription::dispatch($row->id, 'pingly-renew-inv-'.$invoice->id);
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
     * Cancel = permanent revoke at ProxyBR (instances deleted, proxies
     * released). Linked connections are deactivated and released.
     */
    public function cancel(ApiwaySubscription $row): ApiwaySubscription
    {
        if ($row->status->isTerminal()) {
            return $row;
        }

        if ($row->mp_preapproval_id) {
            try {
                $this->billing->mercadoPago()->cancelPreapproval($row->mp_preapproval_id);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel apiway preapproval', [
                    'apiway_subscription_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
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
     * @return array{expired: int, synced: int}
     */
    public function syncStatuses(?Tenant $tenant = null): array
    {
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

        return ['expired' => $expired, 'synced' => $this->reconcileWithPartner($tenant)];
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
        $this->notifier->notifyTenant(NotificationType::ApiwayExpired, $row->tenant, [
            'quantity' => $row->quantity,
        ]);
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

    /** Release every linked connection (asset unlinked, channel deactivated). */
    protected function releaseInstances(ApiwaySubscription $row): void
    {
        foreach ($row->instances()->whereNotNull('connection_id')->with('connection')->get() as $instance) {
            if ($connection = $instance->connection) {
                $connection->update(['status' => ConnectionStatus::Inactive]);
                broadcast(new ConnectionUpdated($connection));
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

    protected function recordPaidCardInvoice(ApiwaySubscription $row, InvoicePurpose $purpose): Invoice
    {
        return Invoice::create([
            'tenant_id' => $row->tenant_id,
            'apiway_subscription_id' => $row->id,
            'purpose' => $purpose,
            'status' => InvoiceStatus::Paid,
            'payment_method' => PaymentMethod::Card,
            'amount_cents' => $row->total_price_cents,
            'currency' => 'BRL',
            'period_start' => now(),
            'paid_at' => now(),
            'mp_preapproval_id' => $row->mp_preapproval_id,
        ]);
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

    public function cycleForBillingCycle(?BillingCycle $cycle): string
    {
        return $cycle === BillingCycle::Yearly ? 'anual' : 'mensal';
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
