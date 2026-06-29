<?php

namespace App\Services\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
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
    ) {}

    /**
     * Subscribe a tenant to a plan via card (recurring) or pix.
     *
     * @param  array{card_token_id?:string, payer_email:string}  $opts
     */
    public function subscribe(Tenant $tenant, Plan $plan, PaymentMethod $method, array $opts): Subscription
    {
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
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Card);

        $payload = [
            'reason' => $plan->name,
            'auto_recurring' => array_merge(
                $plan->billing_cycle->toMercadoPagoFrequency(),
                [
                    'transaction_amount' => $this->toAmount($plan->price_cents),
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
            // Pending authorization — keep waiting; webhook will confirm.
            $subscription->status = SubscriptionStatus::PastDue;
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
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Pix);
        $subscription->status = SubscriptionStatus::PastDue; // becomes active once the pix is paid
        $subscription->save();

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
            'date_of_expiration' => $expiresAt->toIso8601String(),
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
        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->addDays(config('services.mercadopago.grace_days')),
        ]);
        $this->gate->forget($subscription->tenant);
    }

    public function suspend(Subscription $subscription): void
    {
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

    protected function createPendingSubscription(Tenant $tenant, Plan $plan, PaymentMethod $method): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $method) {
            $this->supersedeCurrent($tenant);

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Trialing,
                'payment_method' => $method,
                'billing_cycle' => $plan->billing_cycle->value,
                'price_cents' => $plan->price_cents,
                'quotas_snapshot' => $plan->quotas,
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
        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $subscription->current_period_start ?? now(),
            'current_period_end' => $periodEnd,
            'grace_ends_at' => null,
        ]);
        $this->gate->forget($subscription->tenant);
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
