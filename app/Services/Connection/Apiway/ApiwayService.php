<?php

namespace App\Services\Connection\Apiway;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Credit\CreditTransactionType;
use App\Enums\Notification\NotificationType;
use App\Events\ApiwaySubscriptionUpdated;
use App\Events\ConnectionUpdated;
use App\Exceptions\ApiwayPartnerException;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Jobs\ProvisionApiwaySubscription;
use App\Jobs\RenewApiwaySubscription;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use App\Services\Credits\CreditService;
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
        protected CreditService $credits,
    ) {}

    /** The ledger reference for the first charge of a purchase. */
    public static function purchaseReference(ApiwaySubscription $row): string
    {
        return "apiway:buy:{$row->id}";
    }

    /**
     * The ledger reference for one cycle's renewal.
     *
     * Carries the expiry it is paying to move, because that is what makes the
     * charge unique: the same subscription is renewed again next month, and a
     * reference naming only the subscription would let the ledger refuse the
     * second renewal as a duplicate of the first.
     */
    public static function renewalReference(ApiwaySubscription $row): string
    {
        return "apiway:renew:{$row->id}:".($row->expires_at?->toDateString() ?? 'none');
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
     * Provision instances covered by the plan's `included_instances` quota.
     * No charge — the cost is part of the plan price. Renewed for free while
     * the plan subscription stays usable. The tenant still picks the location
     * (an invalid one is refused by the partner quote/create).
     *
     * Takes a quantity for the same reason the paid path does: a tenant whose
     * plan includes four numbers was opening this dialog four times, and each
     * pass was its own subscription, its own partner call and its own renewal.
     * One row of four is what the allowance actually granted.
     *
     * ⚠️ The cap is `used + quantity`, not `used < quota`. Checking only that
     * one is free would let a request for four go through on the last slot —
     * ProxyBR would provision all four and the plan would be over its allowance
     * with nothing charged for the excess.
     */
    public function createIncludedInstance(
        Tenant $tenant,
        ?string $locationCode = null,
        int $quantity = 1,
    ): ApiwaySubscription {
        if (! $tenant->currentSubscription?->isUsable()) {
            throw ValidationException::withMessages([
                'included' => 'An active plan subscription is required for included instances.',
            ]);
        }

        $quantity = max(1, $quantity);
        $usage = $this->usageSummary($tenant);
        $remaining = max(0, $usage['included_quota'] - $usage['included_used']);

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'included' => 'All instances included in your plan are already in use.',
            ]);
        }

        if ($quantity > $remaining) {
            // Says the number, because "not enough" leaves the customer to work
            // out how many they may ask for by trying again.
            throw ValidationException::withMessages([
                'included' => "Your plan has {$remaining} included instance(s) left.",
            ]);
        }

        $row = $this->createLocalSubscription($tenant, [
            'source' => ApiwaySubscriptionSource::PlanIncluded,
            'status' => ApiwaySubscriptionStatus::Provisioning,
            'quantity' => $quantity,
            'cycle' => $this->cycleForBillingCycle($tenant->currentSubscription->billing_cycle),
            'location_code' => $locationCode ?: $this->defaultLocationCode(),
        ]);

        $this->provisionOrQueue($row, 'included');

        return $row->fresh();
    }

    /**
     * Buy instances at catalog price, paid from the prepaid balance.
     *
     * There is no pending-payment step any more: the balance is already the
     * customer's money, so the charge settles in the same request and the row
     * goes straight to provisioning. That removes the state where an instance
     * existed locally but had not been paid for — which is what `pending_payment`,
     * the Pix QR, the abandon endpoint and the daily sweep of dead checkouts all
     * existed to manage.
     *
     * Charged BEFORE ProxyBR is called, deliberately. Provisioning after the
     * money lands is the rule this whole surface was built on, and it is
     * affordable here in a way it never was with Pix because a failure gives the
     * money straight back to the balance — see markProvisionFailed().
     *
     * @throws InsufficientCreditException when the balance will not cover it
     */
    public function purchaseUnits(
        Tenant $tenant,
        int $quantity,
        string $cycle,
        string $locationCode,
    ): ApiwaySubscription {
        // ProxyBR is the price authority — never trust a client-provided total.
        $quote = $this->partner->quote($quantity, $locationCode, $cycle);
        $totalCents = $this->toCents($quote['total_price'] ?? 0);

        // Checked here as well as inside the debit's lock: this one exists to
        // fail before a subscription row is created, so an unaffordable attempt
        // leaves nothing behind. The one in the lock is the real gate.
        if (! $this->credits->canAfford($tenant, $totalCents)) {
            throw new InsufficientCreditException($this->credits->balanceCents($tenant), $totalCents);
        }

        $row = $this->createLocalSubscription($tenant, [
            'source' => ApiwaySubscriptionSource::Unit,
            'status' => ApiwaySubscriptionStatus::Provisioning,
            'quantity' => $quantity,
            'cycle' => $this->normalizeCycle($quote['cycle'] ?? $cycle),
            'location_code' => $locationCode,
            'unit_price_cents' => $this->toCents($quote['unit_price'] ?? 0),
            'total_price_cents' => $totalCents,
            'meta' => ['quote' => $quote],
        ]);

        try {
            $this->credits->debit(
                $tenant,
                $totalCents,
                CreditTransactionType::Purchase,
                self::purchaseReference($row),
                "API Way — {$quantity} instância(s)",
                ['apiway_subscription_id' => $row->id, 'quantity' => $quantity, 'cycle' => $row->cycle],
            );
        } catch (InsufficientCreditException $e) {
            // Lost the race against a concurrent purchase. Nothing was charged,
            // so the row must not survive to look like an instance being made.
            $row->delete();

            throw $e;
        }

        ApiwaySubscriptionUpdated::dispatch($row->fresh());

        $this->provisionOrQueue($row, 'unit');

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
        $this->notifier->notifyTenant(NotificationType::ApiwayPurchaseActivated, $row->tenant, [
            'quantity' => $row->quantity,
        ]);
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

        // Two different messages because two different things are true. Telling
        // someone "our team will contact you" when their money is already back
        // in their balance sends them to support for a problem that is over.
        $refunded === null
            ? $this->notifier->notifyTenant(NotificationType::ApiwayProvisionFailed, $row->tenant)
            : $this->notifier->notifyTenant(NotificationType::ApiwayProvisionRefunded, $row->tenant, [
                'amount' => 'R$ '.number_format($refunded->amount_cents / 100, 2, ',', '.'),
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
     * Renewals coming up that the balance will not cover.
     *
     * The one question worth asking ahead of an API Way expiry, because it is
     * the only one with an irreversible answer: ProxyBR revokes on the day, so
     * a renewal nobody can pay for is a WhatsApp number that stops existing.
     *
     * ⚠️ Cumulative per tenant, in expiry order. Several subscriptions share one
     * balance, and checking each against the full balance would report three
     * instances as safe when the money only stretches to one. Ordering by expiry
     * makes the arithmetic match what will actually happen: the earliest renewal
     * is charged first and the later ones are the ones that fall off.
     *
     * @return \Illuminate\Support\Collection<int, ApiwaySubscription>
     */
    public function renewalsAtRisk(?Tenant $tenant = null, int $days = 7): \Illuminate\Support\Collection
    {
        $rows = ApiwaySubscription::query()
            ->renewable()
            ->whereNotNull('provider_subscription_id')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->with('tenant.currentSubscription')
            ->orderBy('expires_at')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Legacy rows still being paid elsewhere. Loaded in one query rather
        // than per row: this runs on every dashboard load.
        $withOpenInvoice = Invoice::query()
            ->whereIn('apiway_subscription_id', $rows->pluck('id'))
            ->where('purpose', InvoicePurpose::ApiwayRenewal->value)
            ->where('status', InvoiceStatus::Pending->value)
            ->pluck('apiway_subscription_id')
            ->all();

        $balances = [];
        $committed = [];

        return $rows->filter(function (ApiwaySubscription $row) use (&$balances, &$committed, $withOpenInvoice) {
            if ($row->source === ApiwaySubscriptionSource::PlanIncluded) {
                // Free while the plan lives, so money is not the risk — the plan
                // lapsing is.
                return ! $row->tenant?->currentSubscription?->isUsable();
            }

            if ($row->mp_preapproval_id && ! ($row->meta['autopay_off'] ?? false)) {
                return false;
            }

            if (in_array($row->id, $withOpenInvoice, true)) {
                return false;
            }

            $balances[$row->tenant_id] ??= $this->credits->balanceCents($row->tenant);
            $committed[$row->tenant_id] = ($committed[$row->tenant_id] ?? 0) + $row->total_price_cents;

            return $balances[$row->tenant_id] < $committed[$row->tenant_id];
        })->values();
    }

    /**
     * Charge one cycle to the balance and hand the renewal to the queue.
     *
     * The charge is what this method is for; the renewal itself is the job's
     * job, because ProxyBR can be slow or down and a customer clicking "renew"
     * must not wait on it. The ledger reference doubles as the partner's
     * idempotency key — both need exactly the same thing (one renewal per
     * cycle), and deriving them separately is how they drift apart.
     *
     * ⚠️ Re-quoted first, always. ProxyBR prices the renewal at call time from
     * the current catalog, so charging the price stored on the row would take an
     * amount their invoice then disagrees with.
     *
     * @return bool false when the balance will not cover it — the caller decides
     *              what that means, because it means different things to a
     *              scheduled renewal and to a person who just clicked a button.
     */
    public function renewFromBalance(ApiwaySubscription $row, ?string $cycle = null): bool
    {
        $quote = $this->partner->quote($row->quantity, $row->location_code, $cycle ?? $row->cycle);

        $row->update([
            'unit_price_cents' => $this->toCents($quote['unit_price'] ?? 0),
            'total_price_cents' => $this->toCents($quote['total_price'] ?? 0),
        ]);

        $row->refresh();
        $reference = self::renewalReference($row);

        try {
            // A null return means this cycle was already charged — a run that
            // died between the debit and the dispatch. The work still has to
            // happen, so it falls through to the dispatch below rather than
            // reporting success and doing nothing.
            $this->credits->debit(
                $row->tenant,
                $row->total_price_cents,
                CreditTransactionType::Renewal,
                $reference,
                "Renovação API Way — {$row->quantity} instância(s)",
                ['apiway_subscription_id' => $row->id, 'expires_at' => $row->expires_at?->toDateString()],
            );
        } catch (InsufficientCreditException) {
            return false;
        }

        RenewApiwaySubscription::dispatch($row->id, $reference, $cycle);

        return true;
    }

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
     * QR at MercadoPago), cancel the preapproval and DELETE the local row —
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

        if ($row->mp_preapproval_id) {
            try {
                $this->billing->mercadoPago()->cancelPreapproval($row->mp_preapproval_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to cancel preapproval while abandoning apiway purchase', [
                    'apiway_subscription_id' => $row->id,
                    'error' => $e->getMessage(),
                ]);
            }
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
