<?php

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Notification\NotificationType;
use App\Events\SubscriptionUpdated;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\MercadoPago\MercadoPagoClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single source of truth for subscription/invoice state. Every controller,
 * scheduler command and webhook goes through here so transitions, entitlement
 * snapshots, the tenant.current_subscription_id pointer and the broadcast event
 * stay consistent.
 */
class BillingService
{
    public function __construct(
        protected MercadoPagoClient $mp,
        protected SubscriptionGate $gate,
        protected BillingNotifier $notifier,
    ) {}

    /**
     * Subscribe a tenant to a plan via card (recurring) or pix.
     *
     * @param  array{card_token_id?:string, payer_email:string, quantity?:int}  $opts
     */
    public function subscribe(Tenant $tenant, Plan $plan, PaymentMethod $method, array $opts): Subscription
    {
        $opts['quantity'] = $plan->quantity_enabled
            ? max(1, (int) ($opts['quantity'] ?? 1))
            : 1;

        return match ($method) {
            PaymentMethod::Card => $this->subscribeWithCard($tenant, $plan, $opts),
            PaymentMethod::Pix => $this->subscribeWithPix($tenant, $plan, $opts),
            PaymentMethod::Manual => throw new \InvalidArgumentException('Use grantManual for manual subscriptions.'),
        };
    }

    /**
     * Card: create a MercadoPago preapproval (auto-debit) and activate.
     */
    protected function subscribeWithCard(Tenant $tenant, Plan $plan, array $opts): Subscription
    {
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Card, $opts['quantity'] ?? 1);

        $payload = [
            'reason' => $plan->name,
            'auto_recurring' => array_merge(
                $plan->billing_cycle->toMercadoPagoFrequency(),
                [
                    'transaction_amount' => $this->toAmount($subscription->price_cents),
                    'currency_id' => $plan->currency,
                ],
            ),
            'payer_email' => $opts['payer_email'],
            'card_token_id' => $opts['card_token_id'] ?? null,
            'back_url' => \App\Services\Billing\MercadoPago\MercadoPagoConfig::backUrl(),
            'status' => 'authorized',
            'external_reference' => $this->externalReference($tenant, $subscription),
        ];

        $response = $this->mp->createPreapproval($payload, (string) Str::uuid());

        $subscription->mp_preapproval_id = $response['id'] ?? null;

        if (($response['status'] ?? null) === 'authorized') {
            $this->activate($subscription, $this->nextPeriodEnd($subscription, now()));
            $this->recordPaidInvoice($subscription, $response['id'] ?? null, PaymentMethod::Card);
        } else {
            // Pending authorization — stays past_due until the webhook confirms.
            // The save still matters: it persists mp_preapproval_id.
            $subscription->save();
        }

        $this->fireUpdated($subscription);

        return $subscription;
    }

    /**
     * Pix: create a pending subscription + first pix charge.
     */
    protected function subscribeWithPix(Tenant $tenant, Plan $plan, array $opts): Subscription
    {
        // Starts past_due (createPendingSubscription); becomes active once the pix is paid.
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Pix, $opts['quantity'] ?? 1);

        $this->createPixInvoice($subscription, $opts['payer_email']);
        $this->fireUpdated($subscription);

        return $subscription;
    }

    /**
     * Create (or refresh) a pending Pix invoice for a subscription's next period.
     */
    public function createPixInvoice(Subscription $subscription, ?string $payerEmail = null): Invoice
    {
        $plan = $subscription->plan;
        $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end->copy()
            : now();
        $periodEnd = $this->nextPeriodEnd($subscription, $periodStart);
        $expiresAt = now()->addDay();
        $payerEmail ??= $subscription->tenant->user?->email ?? 'no-reply@example.com';

        $invoice = Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Pix,
            'amount_cents' => $subscription->price_cents,
            'currency' => $plan?->currency ?? 'BRL',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $periodEnd?->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $payload = [
            'transaction_amount' => $this->toAmount($invoice->amount_cents),
            'description' => "Assinatura {$plan?->name} — fatura #{$invoice->id}",
            'payment_method_id' => 'pix',
            'payer' => ['email' => $payerEmail],
            // MercadoPago demands milliseconds here (yyyy-MM-dd'T'HH:mm:ss.SSSZ).
            // toIso8601String() omits them and the API rejects the whole request
            // with "must be valid date and format (yyyy-MM-dd'T'HH:mm:ssz)".
            'date_of_expiration' => $expiresAt->format('Y-m-d\TH:i:s.vP'),
            'external_reference' => $this->externalReference($subscription->tenant, $subscription, $invoice),
        ];

        $response = $this->mp->createPixPayment($payload, $invoice->idempotency_key);
        $txData = $response['point_of_interaction']['transaction_data'] ?? [];

        $invoice->update([
            'mp_payment_id' => isset($response['id']) ? (string) $response['id'] : null,
            'pix_qr_code' => $txData['qr_code'] ?? null,
            'pix_qr_code_base64' => $txData['qr_code_base64'] ?? null,
            'pix_copy_paste' => $txData['qr_code'] ?? null,
            'pix_expires_at' => $expiresAt,
        ]);

        return $invoice;
    }

    /**
     * Apply a MercadoPago payment notification to the matching invoice.
     */
    public function applyPaymentUpdate(array $mpPayment): void
    {
        $paymentId = isset($mpPayment['id']) ? (string) $mpPayment['id'] : null;
        $status = $mpPayment['status'] ?? null;
        if (! $paymentId) {
            return;
        }

        $invoice = Invoice::where('mp_payment_id', $paymentId)->first()
            ?? $this->matchInvoiceByReference($mpPayment['external_reference'] ?? null);

        if (! $invoice) {
            Log::warning('MercadoPago payment with no matching invoice', ['payment_id' => $paymentId]);
            return;
        }

        DB::transaction(function () use ($invoice, $paymentId, $status) {
            $subscription = Subscription::lockForUpdate()->find($invoice->subscription_id);
            $invoice->refresh();

            if ($invoice->status === InvoiceStatus::Paid) {
                return; // idempotent — already applied
            }

            $invoice->mp_payment_id ??= $paymentId;

            match ($status) {
                'approved' => $this->onInvoicePaid($subscription, $invoice),
                'refunded', 'charged_back' => $invoice->update(['status' => InvoiceStatus::Refunded]),
                'rejected', 'cancelled' => $invoice->update(['status' => InvoiceStatus::Failed]),
                default => $invoice->save(), // pending / in_process — leave as-is
            };
        });
    }

    /**
     * Reconcile a card subscription from a MercadoPago preapproval notification.
     */
    public function reconcilePreapproval(array $preapproval): void
    {
        $id = $preapproval['id'] ?? null;
        if (! $id) {
            return;
        }

        $subscription = Subscription::where('mp_preapproval_id', $id)->first();
        if (! $subscription) {
            return;
        }

        DB::transaction(function () use ($subscription, $preapproval) {
            $subscription = Subscription::lockForUpdate()->find($subscription->id);

            match ($preapproval['status'] ?? null) {
                'authorized' => $subscription->status->isUsable()
                    ? null
                    : $this->activate($subscription, $this->nextPeriodEnd($subscription, now())),
                'paused' => $this->markPastDue($subscription),
                'cancelled' => $this->markCancelled($subscription),
                default => null,
            };

            $this->fireUpdated($subscription);
        });
    }

    /**
     * Record a recurring auto-debit charge on a card subscription (MercadoPago
     * `subscription_authorized_payment`). This is what actually renews a card plan each
     * cycle: it creates a paid invoice for the next period and advances current_period_end.
     *
     * @param  array  $authorizedPayment  { preapproval_id, status, payment: { id, status } }
     */
    public function recordRecurringPayment(array $authorizedPayment, bool $force = false): void
    {
        $preapprovalId = $authorizedPayment['preapproval_id'] ?? null;
        $payment = $authorizedPayment['payment'] ?? [];
        $paymentId = isset($payment['id']) ? (string) $payment['id'] : null;
        // The nested payment carries the real charge status; fall back to the envelope.
        $status = $payment['status'] ?? ($authorizedPayment['status'] ?? null);

        if (! $preapprovalId) {
            return;
        }

        $subscription = Subscription::where('mp_preapproval_id', $preapprovalId)->first();
        if (! $subscription) {
            Log::warning('MercadoPago recurring charge with no matching subscription', ['preapproval_id' => $preapprovalId]);
            return;
        }

        // Only approved charges renew. Rejected/failed cycles are left for the preapproval
        // status webhook + billing:process-overdue to grace/suspend.
        if (! in_array($status, ['approved', 'processed'], true)) {
            return;
        }

        // Idempotency (exact): this payment already produced an invoice.
        if ($paymentId && Invoice::where('mp_payment_id', $paymentId)->exists()) {
            return;
        }

        DB::transaction(function () use ($subscription, $paymentId, $force) {
            $subscription = Subscription::lockForUpdate()->find($subscription->id);

            // Webhook path: skip the initial authorization charge (subscribeWithCard already
            // covered the first period) and any charge while the period is still comfortably
            // active — a genuine renewal fires at/after the boundary. The reconcile path
            // passes $force=true because it already dedups per-payment (see syncCardSubscription).
            if (! $force && $subscription->current_period_end && $subscription->current_period_end->gt(now()->addHours(12))) {
                return;
            }

            $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
                ? $subscription->current_period_end
                : now();
            $periodEnd = $this->nextPeriodEnd($subscription, $periodStart);

            Invoice::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'status' => InvoiceStatus::Paid,
                'payment_method' => PaymentMethod::Card,
                'amount_cents' => $subscription->price_cents,
                'currency' => $subscription->plan?->currency ?? 'BRL',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'paid_at' => now(),
                'mp_payment_id' => $paymentId,
                'mp_preapproval_id' => $subscription->mp_preapproval_id,
            ]);

            $this->activate($subscription, $periodEnd);
            $this->fireUpdated($subscription);
        });
    }

    /**
     * Pull model for card subscriptions: reconcile a subscription straight from
     * MercadoPago without waiting for a webhook. Syncs the preapproval status, then
     * lists the subscription's charges and applies any new approved ones. Safe to run
     * repeatedly (idempotent). Returns a small summary for command/CLI output.
     *
     * This is the manual counterpart to the subscription_authorized_payment webhook —
     * use it when webhooks can't be configured (e.g. shared MercadoPago credentials).
     *
     * @return array{skipped?:bool, preapproval_status?:string|null, applied:int, linked:int}
     */
    public function syncCardSubscription(Subscription $subscription): array
    {
        if ($subscription->payment_method !== PaymentMethod::Card || ! $subscription->mp_preapproval_id) {
            return ['skipped' => true, 'applied' => 0, 'linked' => 0];
        }

        $preapprovalStatus = null;

        // 1) Sync the preapproval status (authorized / paused / cancelled).
        try {
            $preapproval = $this->mp->getPreapproval($subscription->mp_preapproval_id);
            $preapprovalStatus = $preapproval['status'] ?? null;
            $this->reconcilePreapproval($preapproval);
        } catch (\Throwable $e) {
            Log::warning('syncCardSubscription: preapproval fetch failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 2) List this subscription's charges and apply new approved ones. Charges carry
        //    the preapproval's external_reference (tenant:X:sub:Y).
        $applied = 0;
        $linked = 0;

        try {
            $ref = $this->externalReference($subscription->tenant, $subscription);
            $result = $this->mp->searchPayments([
                'external_reference' => $ref,
                'sort' => 'date_created',
                'criteria' => 'asc',
            ]);

            foreach (($result['results'] ?? []) as $payment) {
                $paymentId = isset($payment['id']) ? (string) $payment['id'] : null;
                $status = $payment['status'] ?? null;

                if (! $paymentId || $status !== 'approved') {
                    continue;
                }

                // Already linked to an invoice → nothing to do.
                if (Invoice::where('mp_payment_id', $paymentId)->exists()) {
                    continue;
                }

                // Backfill the initial charge: the first paid invoice was recorded at
                // subscribe time without a payment id. Link it instead of extending —
                // this is what stops the first charge from being counted as a renewal.
                $unlinked = $subscription->invoices()
                    ->where('status', InvoiceStatus::Paid->value)
                    ->whereNull('mp_payment_id')
                    ->orderBy('period_start')
                    ->first();

                if ($unlinked) {
                    $unlinked->update(['mp_payment_id' => $paymentId]);
                    $linked++;
                    continue;
                }

                // A genuinely new approved charge → extend the period (force past the
                // webhook-only guard; dedup above already handled the initial charge).
                $this->recordRecurringPayment([
                    'preapproval_id' => $subscription->mp_preapproval_id,
                    'status' => $status,
                    'payment' => ['id' => $paymentId, 'status' => $status],
                ], force: true);

                $applied++;
            }
        } catch (\Throwable $e) {
            Log::warning('syncCardSubscription: payment search failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'preapproval_status' => $preapprovalStatus,
            'applied' => $applied,
            'linked' => $linked,
        ];
    }

    /**
     * Super-admin manual / comp grant — bypasses MercadoPago entirely.
     */
    public function grantManual(Tenant $tenant, ?Plan $plan, ?CarbonInterface $endsAt, User $admin, ?string $note = null): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $endsAt, $admin, $note) {
            $this->supersedeCurrent($tenant);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan?->id,
                'status' => SubscriptionStatus::Manual,
                'payment_method' => PaymentMethod::Manual,
                'billing_cycle' => $plan?->billing_cycle?->value,
                'price_cents' => 0,
                'quantity' => 1,
                'quotas_snapshot' => $plan?->quotas,
                'features_snapshot' => $plan?->features,
                'current_period_start' => now(),
                'current_period_end' => $endsAt,
                'manual_granted_by' => $admin->id,
                'manual_note' => $note,
            ]);

            $this->setCurrent($tenant, $subscription);
            $this->fireUpdated($subscription);

            return $subscription;
        });
    }

    /**
     * Cancel at period end (also cancels the MercadoPago preapproval for cards).
     */
    public function cancel(Subscription $subscription): Subscription
    {
        if ($subscription->payment_method === PaymentMethod::Card && $subscription->mp_preapproval_id) {
            try {
                $this->mp->cancelPreapproval($subscription->mp_preapproval_id);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel MercadoPago preapproval', ['error' => $e->getMessage()]);
            }
        }

        $subscription->update([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
        ]);

        $this->fireUpdated($subscription);

        return $subscription;
    }

    public function markPastDue(Subscription $subscription): void
    {
        // Already past due — leave the grace window alone. reconcilePreapproval()
        // calls this unconditionally for 'paused' preapprovals and billing:pull-cards
        // runs every 15 minutes, so re-stamping grace_ends_at here kept pushing the
        // deadline forward and suspend() could never fire.
        if ($subscription->status === SubscriptionStatus::PastDue) {
            return;
        }

        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->addDays(config('services.mercadopago.grace_days')),
        ]);
        $this->gate->forget($subscription->tenant);
        $this->notifier->notify(NotificationType::SubscriptionPastDue, $subscription);
    }

    public function suspend(Subscription $subscription): void
    {
        if ($subscription->status === SubscriptionStatus::Suspended) {
            return;
        }

        if ($subscription->payment_method === PaymentMethod::Card && $subscription->mp_preapproval_id) {
            try {
                $this->mp->cancelPreapproval($subscription->mp_preapproval_id);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel preapproval on suspend', ['error' => $e->getMessage()]);
            }
        }

        $subscription->update(['status' => SubscriptionStatus::Suspended]);
        $this->gate->forget($subscription->tenant);
        $this->fireUpdated($subscription);
        $this->notifier->notify(NotificationType::SubscriptionSuspended, $subscription);
    }

    public function changeQuantity(Subscription $subscription, int $newQuantity): Subscription
    {
        $newQuantity = max(1, $newQuantity);
        $subscription->loadMissing('plan');
        $plan = $subscription->plan;

        if (! $plan?->quantity_enabled) {
            throw new \InvalidArgumentException('Subscription plan does not support quantity changes.');
        }

        $total = $plan->price_cents * $newQuantity;

        if ($subscription->payment_method === PaymentMethod::Card && $subscription->mp_preapproval_id) {
            try {
                $this->mp->updatePreapproval($subscription->mp_preapproval_id, [
                    'auto_recurring' => [
                        'transaction_amount' => $this->toAmount($total),
                        'currency_id' => $plan->currency,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to update MercadoPago preapproval amount', [
                    'subscription_id' => $subscription->id,
                    'mp_preapproval_id' => $subscription->mp_preapproval_id,
                    'error' => $e->getMessage(),
                ]);

                throw new \RuntimeException('Failed to update MercadoPago preapproval amount.', 0, $e);
            }
        }

        $subscription->update([
            'price_cents' => $total,
            'quantity' => $newQuantity,
            'quotas_snapshot' => array_merge($subscription->quotas_snapshot ?? [], [
                'max_instances' => $newQuantity,
            ]),
        ]);

        $this->gate->forget($subscription->tenant);
        $this->fireUpdated($subscription);

        return $subscription;
    }

    // --- internals -------------------------------------------------------

    protected function onInvoicePaid(Subscription $subscription, Invoice $invoice): void
    {
        // Honor late pix payments: extend from the later of now / current end.
        $base = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end
            : now();

        $invoice->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->activate($subscription, $invoice->period_end ?? $this->nextPeriodEnd($subscription, $base));
        $this->fireUpdated($subscription);
    }

    protected function createPendingSubscription(Tenant $tenant, Plan $plan, PaymentMethod $method, int $quantity = 1): Subscription
    {
        $quantity = $plan->quantity_enabled ? max(1, $quantity) : 1;
        $quotas = $plan->quotas;

        if ($plan->quantity_enabled) {
            $quotas = array_merge($plan->quotas ?? [], ['max_instances' => $quantity]);
        }

        return DB::transaction(function () use ($tenant, $plan, $method, $quantity, $quotas) {
            $this->supersedeCurrent($tenant);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                // Must NOT be a usable status. This row is committed and pointed at
                // by tenant.current_subscription_id before the provider is called,
                // so anything usable here grants free access when that call throws —
                // and with no current_period_end it would never lapse either.
                // Callers move it to Active once payment is confirmed.
                'status' => SubscriptionStatus::PastDue,
                'payment_method' => $method,
                'billing_cycle' => $plan->billing_cycle->value,
                'price_cents' => $plan->price_cents * $quantity,
                'quantity' => $quantity,
                'quotas_snapshot' => $quotas,
                'features_snapshot' => $plan->features,
                'current_period_start' => now(),
                'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
            ]);

            $this->setCurrent($tenant, $subscription);

            return $subscription;
        });
    }

    protected function activate(Subscription $subscription, ?CarbonInterface $periodEnd): void
    {
        // Renewals land here too (recordRecurringPayment / onInvoicePaid run every
        // cycle), so only a real transition into Active is worth announcing —
        // otherwise a card tenant is congratulated every month.
        $wasActive = $subscription->status === SubscriptionStatus::Active;

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $subscription->current_period_start ?? now(),
            'current_period_end' => $periodEnd,
            'grace_ends_at' => null,
        ]);
        $this->gate->forget($subscription->tenant);

        if (! $wasActive) {
            $this->notifier->notify(NotificationType::SubscriptionActivated, $subscription);
        }
    }

    protected function markCancelled(Subscription $subscription): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
        $this->gate->forget($subscription->tenant);
    }

    protected function recordPaidInvoice(Subscription $subscription, ?string $mpPaymentId, PaymentMethod $method): Invoice
    {
        return Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Paid,
            'payment_method' => $method,
            'amount_cents' => $subscription->price_cents,
            'currency' => $subscription->plan?->currency ?? 'BRL',
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'paid_at' => now(),
            'mp_preapproval_id' => $subscription->mp_preapproval_id,
        ]);
    }

    protected function supersedeCurrent(Tenant $tenant): void
    {
        $current = $tenant->currentSubscription;
        if ($current && $current->status !== SubscriptionStatus::Cancelled) {
            $current->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
        }
    }

    protected function setCurrent(Tenant $tenant, Subscription $subscription): void
    {
        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
        $this->gate->forget($tenant);
    }

    protected function matchInvoiceByReference(?string $reference): ?Invoice
    {
        if (! $reference || ! preg_match('/inv:(\d+)/', $reference, $m)) {
            return null;
        }

        return Invoice::find((int) $m[1]);
    }

    protected function externalReference(Tenant $tenant, Subscription $subscription, ?Invoice $invoice = null): string
    {
        $ref = "tenant:{$tenant->id}:sub:{$subscription->id}";

        return $invoice ? "{$ref}:inv:{$invoice->id}" : $ref;
    }

    protected function nextPeriodEnd(Subscription $subscription, CarbonInterface $from): CarbonInterface
    {
        return $subscription->billing_cycle->advance(Carbon::instance($from));
    }

    protected function toAmount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    protected function fireUpdated(Subscription $subscription): void
    {
        SubscriptionUpdated::dispatch($subscription->fresh());
    }
}
