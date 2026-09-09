<?php

namespace App\Services\Billing;

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Notification\NotificationType;
use App\Events\SubscriptionUpdated;
use App\Exceptions\Billing\MissingBillingIdentityException;
use App\Exceptions\Billing\PaymentAlreadySettledException;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TrainedAgentHire;
use App\Models\ApiwaySubscription;
use App\Services\Billing\PaymentService\PaymentServiceClient;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Credits\CreditService;
use App\Services\TrainedAgent\TrainedAgentService;
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
 *
 * ⚠️ One thing changed shape when this moved off MercadoPago, and it explains
 * most of the class: **there is no preapproval any more.** No gateway debits a
 * card on a schedule of its own. The payment service holds a stored instrument
 * and charges it when asked, which means the renewal clock is ours — it lives
 * in `billing:charge-renewals` and `chargeRenewal()` below. What used to be
 * three inbound webhook shapes (payment, preapproval, authorized_payment) is
 * now one: a payment changed.
 */
class BillingService
{
    public function __construct(
        protected PaymentServiceClient $payments,
        protected SubscriptionGate $gate,
        protected BillingNotifier $notifier,
    ) {}

    /**
     * How long a Pix instruction stays payable.
     *
     * ⚠️ Set explicitly, and it matters more than it used to. The payment
     * service has no way to cancel a pending Pix — `void` releases a card
     * authorisation and refuses anything else — so this window is the only
     * thing that eventually kills a QR the customer walked away from.
     * Cancelling now stops us showing it and stops it counting; it does not
     * stop it being payable. If somebody pays anyway the webhook honours it
     * (see applyPaymentUpdate), which is the right answer — the money arrived.
     *
     * Not shortened to minutes on purpose: someone paying a monthly plan opens
     * their bank app, and a code that expires while they are doing that turns
     * our gap into their failed payment.
     */
    protected const PIX_WINDOW_HOURS = 24;

    /**
     * Subscribe a tenant to a plan via card (recurring) or pix.
     *
     * @param  array{card_token?:string, provider?:string, payer_email:string}  $opts
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
     * Card: charge now, and keep the instrument for the cycles after this one.
     *
     * A card is synchronous — the response to this one call carries the final
     * outcome, unlike a Pix which returns an instruction and then waits. So
     * there is no pending state to render and no webhook to wait for.
     *
     * `store_instrument` deliberately stores *after* the charge succeeds. A
     * card kept without ever being charged is regularly found to be invalid the
     * first time it is used, which produces a subscription that looks healthy
     * right up until its first renewal.
     */
    protected function subscribeWithCard(Tenant $tenant, Plan $plan, array $opts): Subscription
    {
        $this->assertBillable($tenant);

        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Card);
        $periodEnd = $this->nextPeriodEnd($subscription, now());

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'amount_cents' => $subscription->price_cents,
            'currency' => $plan->currency ?? 'BRL',
            'period_start' => $subscription->current_period_start,
            'period_end' => $periodEnd,
            'order_reference' => $this->orderReference($subscription, $subscription->current_period_start),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->payments->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => 'card',
                'card_token' => $opts['card_token'] ?? null,
                'installments' => 1,
                'initiator' => 'customer',
                'store_instrument' => true,
                'description' => "Assinatura {$plan->name}",
                // The gateway that minted the token in the browser. A token
                // belongs to one gateway and is meaningless to another, so this
                // is the one payment where the provider is not ours to choose.
                'provider' => $opts['provider'] ?? null,
                'customer' => $this->customerPayload($tenant, $opts['payer_email'] ?? null),
                'metadata' => ['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            // The row exists before the call because its reference goes in the
            // payload; a refusal must not strand it looking payable.
            $invoice->update(['status' => InvoiceStatus::Failed]);

            throw $e;
        }

        $payment = $response['data'] ?? [];

        $invoice->update([
            'payment_id' => isset($payment['id']) ? (string) $payment['id'] : null,
        ]);

        $this->rememberInstrument($subscription, $payment);

        if (($payment['status'] ?? null) === 'paid') {
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
            $this->activate($subscription, $periodEnd);
        } else {
            // Declined, or the rare `unknown` where the gateway never answered.
            // Neither activates anything; `unknown` resolves by itself and its
            // webhook lands on this same invoice through order_reference.
            $invoice->update([
                'status' => ($payment['status'] ?? null) === 'unknown'
                    ? InvoiceStatus::Pending
                    : InvoiceStatus::Failed,
            ]);
        }

        $this->fireUpdated($subscription);

        return $subscription->fresh();
    }

    /**
     * Pix: create a pending subscription + first pix charge.
     */
    protected function subscribeWithPix(Tenant $tenant, Plan $plan, array $opts): Subscription
    {
        $this->assertBillable($tenant);

        // Starts past_due (createPendingSubscription); becomes active once the pix is paid.
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Pix);

        $this->createPixInvoice($subscription, $opts['payer_email'] ?? null);
        $this->fireUpdated($subscription);

        return $subscription;
    }

    /**
     * Create (or refresh) a pending Pix invoice for a subscription's next period.
     */
    public function createPixInvoice(Subscription $subscription, ?string $payerEmail = null): Invoice
    {
        $tenant = $subscription->tenant;
        $this->assertBillable($tenant);

        $plan = $subscription->plan;
        $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end->copy()
            : now();
        $periodEnd = $this->nextPeriodEnd($subscription, $periodStart);
        $expiresAt = now()->addHours(self::PIX_WINDOW_HOURS);

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
            'order_reference' => $this->orderReference($subscription, $periodStart),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->payments->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => 'pix',
                'description' => "Assinatura {$plan?->name} — fatura #{$invoice->id}",
                'expires_at' => $expiresAt->toIso8601String(),
                'customer' => $this->customerPayload($tenant, $payerEmail),
                'metadata' => ['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            // The row has to exist before the call (its reference goes in the
            // payload), so a rejection would otherwise strand a pending invoice
            // that looks payable but carries no QR — and the $hasOpen guard in
            // billing:pix-generate would then refuse to issue a real one.
            $invoice->update(['status' => InvoiceStatus::Failed]);

            throw $e;
        }

        $this->applyPixInstructions($invoice, $response['data'] ?? [], $expiresAt);

        return $invoice->fresh();
    }

    /**
     * Create a payable Pix charge that tops up a workspace's prepaid balance.
     *
     * Pix only, and that is not a gap: a top-up is "put R$50 in, whenever you
     * feel like it", which no stored-instrument schedule describes. A card
     * top-up would be a one-off card charge, and this product has nowhere to
     * collect a card outside the subscription checkout.
     *
     * The amount comes from the caller, which is the first time that is true in
     * this class: everywhere else the price belongs to a plan or a quote and
     * client input would be a way to buy something cheaply. Here the customer
     * genuinely chooses how much to deposit, so the only rule is a floor
     * (`ai.credits.min_topup_cents`), enforced by the controller.
     *
     * There is no period. A balance is not a subscription: it does not expire,
     * it does not renew, and `period_start`/`period_end` would only be
     * describing a cycle that does not exist.
     */
    public function createCreditTopupPixInvoice(Tenant $tenant, int $amountCents, ?string $payerEmail = null): Invoice
    {
        $this->assertBillable($tenant);

        $expiresAt = now()->addHours(self::PIX_WINDOW_HOURS);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'purpose' => InvoicePurpose::CreditTopup,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Pix,
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'due_date' => $expiresAt->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // A top-up has no period, so the invoice id is the whole identity —
        // unlike a subscription, where the reference must carry the cycle.
        $invoice->update(['order_reference' => "pingly-topup-{$invoice->id}"]);

        try {
            $response = $this->payments->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => 'BRL',
                'payment_method' => 'pix',
                'description' => "Créditos — fatura #{$invoice->id}",
                'expires_at' => $expiresAt->toIso8601String(),
                'customer' => $this->customerPayload($tenant, $payerEmail),
                'metadata' => ['tenant_id' => $tenant->id, 'purpose' => 'credit_topup'],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            $invoice->update(['status' => InvoiceStatus::Failed]);

            throw $e;
        }

        $this->applyPixInstructions($invoice, $response['data'] ?? [], $expiresAt);

        return $invoice->fresh();
    }

    /**
     * Charge a cycle against the card stored at subscribe time.
     *
     * This is what a MercadoPago preapproval used to do on its own, and moving
     * it here is the substantive change in this migration: nothing outside this
     * codebase renews a subscription any more.
     *
     * The order reference carries the period, so the exactly-once guarantee is
     * the payment service's unique constraint rather than a check of ours — a
     * double-firing scheduler, two racing workers, and an operator pressing
     * retry all converge on the same payment.
     *
     * @return Invoice|null  null when there was nothing to charge.
     */
    public function chargeRenewal(Subscription $subscription): ?Invoice
    {
        if ($subscription->payment_method !== PaymentMethod::Card || ! $subscription->payment_instrument_id) {
            return null;
        }

        $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end->copy()
            : now();
        $periodEnd = $this->nextPeriodEnd($subscription, $periodStart);
        $reference = $this->orderReference($subscription, $periodStart);

        // Cheap local guard so a re-run does not even make the call. The
        // service's constraint is what actually guarantees it.
        //
        // Both shapes, because a cycle already invoiced under the colon form
        // must still read as billed — see legacyOrderReference().
        $alreadyBilled = Invoice::whereIn('order_reference', [
            $reference,
            $this->legacyOrderReference($subscription, $periodStart),
        ])->exists();

        if ($alreadyBilled) {
            return null;
        }

        $invoice = Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'amount_cents' => $subscription->price_cents,
            'currency' => $subscription->plan?->currency ?? 'BRL',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'order_reference' => $reference,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $response = $this->payments->createPayment([
                'order_reference' => $reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => 'card',
                'instrument_id' => $subscription->payment_instrument_id,
                // Nobody is at a screen: no security code, no 3-D Secure, and
                // the provider must be one that can take such a charge at all.
                'initiator' => 'merchant',
                'description' => "Renovação {$subscription->plan?->name}",
                'metadata' => ['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            $invoice->update(['status' => InvoiceStatus::Failed]);

            Log::warning('Card renewal charge failed', [
                'subscription_id' => $subscription->id,
                'order_reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            // Not rethrown: the caller is a scheduler walking a list, and one
            // dead card must not stop the rest of the run. The subscription
            // lapses through billing:process-overdue like any unpaid cycle.
            return $invoice->fresh();
        }

        $payment = $response['data'] ?? [];

        $invoice->update(['payment_id' => isset($payment['id']) ? (string) $payment['id'] : null]);

        if (($payment['status'] ?? null) === 'paid') {
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
            $this->activate($subscription, $periodEnd);
            $this->fireUpdated($subscription);

            return $invoice->fresh();
        }

        $this->handleRenewalDecline($subscription, $invoice, $payment);

        return $invoice->fresh();
    }

    /**
     * Apply a payment-service notification to the matching invoice.
     *
     * @param  array  $payment  Either a webhook `data` block or a fetched payment.
     */
    public function applyPaymentUpdate(array $payment): void
    {
        $paymentId = $this->paymentIdOf($payment);
        $status = $payment['status'] ?? null;

        $invoice = $this->matchInvoice($payment);

        if (! $invoice) {
            Log::warning('Payment with no matching invoice', [
                'payment_id' => $paymentId,
                'order_reference' => $payment['order_reference'] ?? null,
            ]);

            return;
        }

        // Asset purchases (API Way instances, trained agents, top-ups) have no
        // plan subscription behind them — their paid hook provisions the asset
        // via queued jobs instead of moving subscription state.
        if ($invoice->purpose !== null && $invoice->purpose->isAssetPurchase()) {
            $this->applyAssetPaymentUpdate($invoice, $paymentId, $status);

            return;
        }

        DB::transaction(function () use ($invoice, $paymentId, $status) {
            $subscription = Subscription::lockForUpdate()->find($invoice->subscription_id);
            $invoice->refresh();

            // Already paid: a repeat is a duplicate notification, and a late
            // decline must never un-pay a settled invoice. Only a refund or a
            // dispute may still move it — those arrive precisely because it was
            // paid, so a blanket return here would make the refund arm below
            // dead code.
            if ($invoice->status === InvoiceStatus::Paid && ! $this->reversesAPayment($status)) {
                return;
            }

            if ($paymentId && ! $invoice->payment_id) {
                $invoice->payment_id = $paymentId;
            }

            match (true) {
                $status === 'paid' => $this->onInvoicePaid($subscription, $invoice),
                $this->reversesAPayment($status) => $invoice->update(['status' => InvoiceStatus::Refunded]),
                $status === 'expired' => $invoice->update(['status' => InvoiceStatus::Expired]),
                in_array($status, ['declined', 'voided'], true) => $invoice->update(['status' => InvoiceStatus::Failed]),
                // created / pending / authorized — and `unknown`, which is the
                // one worth naming: the gateway did not answer, so nobody knows
                // whether the charge exists. It is not a failure and must never
                // be treated as one; it resolves by itself and notifies again.
                default => $invoice->save(),
            };
        });
    }

    /**
     * Settle a payment against an asset invoice (API Way, trained agent,
     * top-up). Mirrors the subscription path's guards but never touches
     * Subscription state; the paid hook only flips local status and dispatches
     * provisioning jobs (no partner/hub HTTP inside the webhook transaction).
     */
    protected function applyAssetPaymentUpdate(Invoice $invoice, ?string $paymentId, ?string $status): void
    {
        DB::transaction(function () use ($invoice, $paymentId, $status) {
            if ($invoice->apiway_subscription_id) {
                ApiwaySubscription::lockForUpdate()->find($invoice->apiway_subscription_id);
            }

            if ($invoice->trained_agent_hire_id) {
                TrainedAgentHire::lockForUpdate()->find($invoice->trained_agent_hire_id);
            }

            $invoice->refresh();

            if ($invoice->status === InvoiceStatus::Paid && ! $this->reversesAPayment($status)) {
                return;
            }

            if ($paymentId && ! $invoice->payment_id) {
                $invoice->payment_id = $paymentId;
            }

            match (true) {
                $status === 'paid' => tap($invoice)->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]),
                $this->reversesAPayment($status) => $invoice->update(['status' => InvoiceStatus::Refunded]),
                $status === 'expired' => $invoice->update(['status' => InvoiceStatus::Expired]),
                in_array($status, ['declined', 'voided'], true) => $invoice->update(['status' => InvoiceStatus::Failed]),
                default => $invoice->save(),
            };

            if ($status === 'paid') {
                $fresh = $invoice->fresh();

                match (true) {
                    $fresh->purpose === InvoicePurpose::TrainedAgentPurchase
                        => app(TrainedAgentService::class)->handleInvoicePaid($fresh),
                    $fresh->purpose === InvoicePurpose::CreditTopup
                        => app(CreditService::class)->creditTopup($fresh),
                    default => app(ApiwayService::class)->handleApiwayInvoicePaid($fresh),
                };
            }

            // A credit top-up is the one asset purchase that can be undone
            // after delivery: the balance is still sitting there, so a refund
            // or chargeback has to take it back out. The other purposes
            // provision something the customer keeps — reversing those is a
            // support decision, not an automatic one.
            if ($this->reversesAPayment($status) && $invoice->purpose === InvoicePurpose::CreditTopup) {
                app(CreditService::class)->reverseTopup($invoice->fresh());
            }
        });
    }

    /**
     * A stored instrument stopped working.
     *
     * Acting on this is what separates "your card expires next month" from
     * "your service stopped": without it, `billing:charge-renewals` keeps
     * charging a dead card, and repeatedly retrying a refused one raises the
     * failure ratio the networks judge every other transaction by.
     */
    public function applyInstrumentUpdate(array $data): void
    {
        $instrumentId = isset($data['instrument_id']) ? (string) $data['instrument_id'] : null;

        if (! $instrumentId) {
            return;
        }

        $subscriptions = Subscription::where('payment_instrument_id', $instrumentId)->get();

        foreach ($subscriptions as $subscription) {
            $subscription->update(['payment_instrument_id' => null]);

            Log::info('Payment instrument detached from subscription', [
                'subscription_id' => $subscription->id,
                'instrument_id' => $instrumentId,
                'reason' => $data['reason'] ?? null,
                'status' => $data['status'] ?? null,
            ]);

            $this->notifier->notify(NotificationType::SubscriptionPastDue, $subscription);
            $this->fireUpdated($subscription);
        }
    }

    /**
     * Super-admin manual / comp grant — bypasses the payment service entirely.
     *
     * The grantor is an `Admin`, never a `User`: this is only reachable from
     * the Back Office, and `subscriptions.manual_granted_by` has held admin ids
     * since they moved out of `users`.
     */
    public function grantManual(Tenant $tenant, ?Plan $plan, ?CarbonInterface $endsAt, Admin $admin, ?string $note = null): Subscription
    {
        $this->voidSupersededCharges($tenant);

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
     * Cancel at period end.
     *
     * Nothing to tell the provider: without a preapproval there is no standing
     * authorisation to revoke — the next cycle simply is not charged, because
     * `billing:charge-renewals` skips a subscription flagged to end.
     */
    public function cancel(Subscription $subscription): Subscription
    {
        // Nothing was ever paid on this one (typically a pix QR that was never
        // settled), so there is no period to run out — tear it down outright and
        // hand the tenant a clean slate. Manual/comp grants have no invoices
        // either, hence the usable-status guard.
        if (! $subscription->status->isUsable() && ! $this->hasSettledInvoice($subscription)) {
            return $this->cancelPendingCheckout($subscription);
        }

        $subscription->update([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
        ]);

        $this->fireUpdated($subscription);

        return $subscription;
    }

    /**
     * Abandon a checkout that was never paid: close the open charge locally,
     * mark the subscription cancelled and detach it from the tenant.
     *
     * Detaching matters — a cancelled row left in tenant.current_subscription_id
     * still reads as "your current plan" everywhere (the plan grid would keep
     * badging it as current and refuse to let the tenant pick it again), while
     * granting nothing. Clearing the pointer puts the tenant back in the same
     * state as before they started, free to choose any plan.
     *
     * @throws PaymentAlreadySettledException if the charge settled mid-cancel.
     */
    public function cancelPendingCheckout(Subscription $subscription): Subscription
    {
        if ($this->hasSettledInvoice($subscription)) {
            throw new PaymentAlreadySettledException;
        }

        $this->voidOpenPixInvoices($subscription);

        // The step above re-reads each charge at the service, so a pix paid
        // seconds before this call has already been applied (and the
        // subscription activated). Never tear that down.
        if ($this->hasSettledInvoice($subscription)) {
            throw new PaymentAlreadySettledException;
        }

        $tenant = $subscription->tenant;

        DB::transaction(function () use ($subscription, $tenant) {
            $subscription->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_at_period_end' => false,
                'grace_ends_at' => null,
            ]);

            // Only if it is still the pointer — a concurrent subscribe may have
            // already moved the tenant onto a newer subscription.
            if ($tenant && $tenant->current_subscription_id === $subscription->id) {
                $tenant->forceFill(['current_subscription_id' => null])->save();
            }
        });

        if ($tenant) {
            $this->gate->forget($tenant);
        }

        $this->fireUpdated($subscription);

        return $subscription->fresh();
    }

    /**
     * Close every still-open pix charge on a subscription. Returns how many
     * were cancelled.
     */
    public function voidOpenPixInvoices(Subscription $subscription): int
    {
        $open = $subscription->invoices()
            ->where('status', InvoiceStatus::Pending->value)
            ->where('payment_method', PaymentMethod::Pix->value)
            ->get();

        $cancelled = 0;

        foreach ($open as $invoice) {
            $cancelled += $this->cancelInvoice($invoice)->status === InvoiceStatus::Cancelled ? 1 : 0;
        }

        return $cancelled;
    }

    /**
     * Close a single pending charge.
     *
     * ⚠️ Local only. The payment service cannot cancel a pending Pix — `void`
     * releases a card authorisation and refuses everything else — so the QR
     * stays payable until `expires_at` (see PIX_WINDOW_HOURS). What this does
     * is stop offering it and stop counting it.
     *
     * The read before the write is therefore the important half: it honours a
     * payment that landed in the seconds before the customer pressed cancel,
     * rather than voiding a charge they actually made.
     */
    public function cancelInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            return $invoice;
        }

        if ($invoice->payment_id) {
            try {
                $payment = $this->payments->getPayment($invoice->payment_id);

                if (($payment['status'] ?? null) === 'paid') {
                    $this->applyPaymentUpdate($payment);

                    return $invoice->fresh();
                }
            } catch (\Throwable $e) {
                // Service unreachable — fall through and close locally. The
                // webhook still arrives if it is paid later.
                Log::warning('Could not re-read a charge before cancelling it', [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $invoice->payment_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $invoice->update(['status' => InvoiceStatus::Cancelled]);

        return $invoice->fresh();
    }

    public function markPastDue(Subscription $subscription): void
    {
        // Already past due — leave the grace window alone. Re-stamping
        // grace_ends_at on every scheduler pass kept pushing the deadline
        // forward, and suspend() could never fire.
        if ($subscription->status === SubscriptionStatus::PastDue) {
            return;
        }

        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'grace_ends_at' => now()->addDays(config('services.billing.grace_days')),
        ]);
        $this->gate->forget($subscription->tenant);
        $this->notifier->notify(NotificationType::SubscriptionPastDue, $subscription);
    }

    public function suspend(Subscription $subscription): void
    {
        if ($subscription->status === SubscriptionStatus::Suspended) {
            return;
        }

        // No standing authorisation to revoke: a stored instrument is only
        // charged when we ask, and a suspended subscription is never asked for.
        $subscription->update(['status' => SubscriptionStatus::Suspended]);
        $this->gate->forget($subscription->tenant);
        $this->fireUpdated($subscription);
        $this->notifier->notify(NotificationType::SubscriptionSuspended, $subscription);
    }

    // --- internals -------------------------------------------------------

    /**
     * The idempotency anchor, and the whole reason a cycle cannot be billed
     * twice.
     *
     * Built from the subscription and the period it covers, never from the
     * attempt. The pair of product and order reference is unique in the payment
     * service's database, so a double-firing scheduler, two racing workers and
     * an operator pressing retry days later all land on the same payment.
     *
     * ⚠️ A random value per attempt would disable that protection entirely, and
     * nothing here would notice — the first sign would be a customer charged
     * twice for one month.
     *
     * ⚠️ Separated by hyphens, not colons, and that is a hard requirement
     * rather than a style choice: dLocal Go forwards this string as the
     * payment's `order_id` and rejects anything outside
     * `[A-Za-z0-9\-_]` — so `pingly:sub:12:2026-08-01` failed *every* payment
     * routed there, not merely some. The colon carried no meaning a hyphen does
     * not, and this charset is the intersection every provider accepts, so it
     * is used for all of them rather than switched on the routed provider.
     * Branching on the provider would be worse than the bug: an operator
     * changing the Back Office setting mid-cycle would give one period two
     * different references, and the uniqueness guarantee only holds while a
     * period maps to exactly one string.
     */
    protected function orderReference(Subscription $subscription, CarbonInterface $periodStart): string
    {
        return "pingly-sub-{$subscription->id}-".Carbon::instance($periodStart)->toDateString();
    }

    /**
     * The colon-separated shape issued before dLocal Go refused it.
     *
     * Read-only, and only where a *local* lookup decides whether a cycle has
     * already been billed. A renewal in flight when this changed has its
     * invoice stored under the old string; a guard that only knew the new one
     * would find nothing, issue a second invoice, and — because the payment
     * service sees a reference it has never had either — take the money twice.
     * That is the exact failure the reference exists to prevent, so it must not
     * be introduced by the fix for it.
     */
    protected function legacyOrderReference(Subscription $subscription, CarbonInterface $periodStart): string
    {
        return "pingly:sub:{$subscription->id}:".Carbon::instance($periodStart)->toDateString();
    }

    /**
     * Who is paying, in the acquirer's terms.
     *
     * `consented_to_stored_instruments` is only ever true for a card checkout,
     * where the customer ticked the box that says so. A pre-ticked box is not
     * consent under LGPD, and the service refuses storage without it.
     */
    protected function customerPayload(Tenant $tenant, ?string $payerEmail = null): array
    {
        $user = $tenant->user;

        return array_filter([
            'reference' => $tenant->paymentCustomerReference(),
            'name' => $tenant->billing_name ?: $user?->name,
            'email' => $payerEmail ?: $user?->email,
            'document_type' => $tenant->billing_document_type,
            'document_number' => $tenant->billing_document_number,
            'document_country' => 'BR',
            'consented_to_stored_instruments' => true,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Refuse before calling out, where the sentence can still say what to do.
     *
     * The service rejects a charge with no CPF or CNPJ by naming
     * `customer.document_number` — a field the person reading it has never seen
     * and cannot find in this product.
     */
    protected function assertBillable(?Tenant $tenant): void
    {
        if (! $tenant?->hasBillingIdentity()) {
            throw new MissingBillingIdentityException;
        }
    }

    /** Copy a Pix instruction onto the invoice columns the SPA already reads. */
    protected function applyPixInstructions(Invoice $invoice, array $payment, CarbonInterface $fallbackExpiry): void
    {
        $instructions = $payment['instructions'] ?? [];

        $invoice->update([
            'payment_id' => isset($payment['id']) ? (string) $payment['id'] : null,
            'pix_qr_code' => $instructions['qr_code'] ?? null,
            'pix_qr_code_base64' => $instructions['qr_code_image'] ?? null,
            'pix_copy_paste' => $instructions['qr_code'] ?? null,
            'pix_expires_at' => isset($instructions['expires_at'])
                ? Carbon::parse($instructions['expires_at'])
                : $fallbackExpiry,
        ]);
    }

    /**
     * Keep the instrument the first charge stored, so later cycles have
     * something to bill.
     *
     * Storage failing never fails the payment — the charge went through and the
     * customer has their plan — so this is a warning, not an error. What it
     * costs is the next renewal, which is why it is logged loudly enough to be
     * found before that renewal comes round.
     */
    protected function rememberInstrument(Subscription $subscription, array $payment): void
    {
        $instrumentId = $payment['instrument']['id'] ?? null;
        $customerId = $payment['customer']['id'] ?? null;

        if ($instrumentId === null && ($payment['status'] ?? null) === 'paid') {
            Log::warning('Card charge succeeded but no instrument was stored', [
                'subscription_id' => $subscription->id,
                'payment_id' => $payment['id'] ?? null,
            ]);
        }

        $subscription->forceFill(array_filter([
            'payment_instrument_id' => $instrumentId === null ? null : (string) $instrumentId,
            'payment_customer_id' => $customerId === null ? null : (string) $customerId,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * A renewal that did not go through.
     *
     * The split is `decline.category`, and it is the only thing worth branching
     * on: a `permanent` refusal — lost, stolen, closed — will refuse again
     * tomorrow, and retrying it raises the failure ratio the card networks
     * judge every other transaction by. So the instrument is dropped and the
     * customer asked for another. A `temporary` one (no funds, limit reached)
     * is left alone; the next scheduler pass reuses the same order reference,
     * so trying again cannot double-charge.
     */
    protected function handleRenewalDecline(Subscription $subscription, Invoice $invoice, array $payment): void
    {
        $status = $payment['status'] ?? null;

        // `unknown` means the gateway never answered. Leave the invoice
        // pending: the payment may well exist, and issuing a second one is
        // exactly how a customer is billed twice.
        if ($status === 'unknown') {
            return;
        }

        $invoice->update(['status' => InvoiceStatus::Failed]);

        $category = $payment['decline']['category'] ?? 'unknown';

        Log::warning('Card renewal declined', [
            'subscription_id' => $subscription->id,
            'order_reference' => $invoice->order_reference,
            'category' => $category,
            'code' => $payment['decline']['code'] ?? null,
        ]);

        if ($category === 'permanent') {
            $subscription->update(['payment_instrument_id' => null]);
        }

        $this->notifier->notify(NotificationType::SubscriptionPastDue, $subscription);
    }

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
        // Before the tenant moves on: close whatever charge the old
        // subscription still has open (talks to the payment service, so keep it
        // out of the transaction).
        $this->voidSupersededCharges($tenant);

        return DB::transaction(function () use ($tenant, $plan, $method) {
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
        // Renewals land here too (chargeRenewal / onInvoicePaid run every
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

    /** Whether any charge on this subscription was ever actually paid. */
    protected function hasSettledInvoice(Subscription $subscription): bool
    {
        return $subscription->invoices()
            ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Refunded->value])
            ->exists();
    }

    protected function supersedeCurrent(Tenant $tenant): void
    {
        $current = $tenant->currentSubscription;
        if ($current && $current->status !== SubscriptionStatus::Cancelled) {
            $current->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
        }
    }

    /**
     * Kill the outgoing subscription's unpaid pix charges before it is replaced.
     * Without this, switching plans leaves the old QR live: paying it would
     * settle an invoice belonging to a superseded subscription — money in, no
     * access, since the tenant pointer has moved on. Runs outside the caller's
     * transaction because it talks to the payment service.
     */
    protected function voidSupersededCharges(Tenant $tenant): void
    {
        if ($current = $tenant->currentSubscription) {
            $this->voidOpenPixInvoices($current);
        }
    }

    protected function setCurrent(Tenant $tenant, Subscription $subscription): void
    {
        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
        $this->gate->forget($tenant);
    }

    /**
     * Find the invoice a payment belongs to.
     *
     * `order_reference` is checked as well as the id, and it is the half that
     * matters when a webhook overtakes the response that created the payment —
     * at that moment the invoice has a reference but no id yet.
     */
    protected function matchInvoice(array $payment): ?Invoice
    {
        $paymentId = $this->paymentIdOf($payment);

        if ($paymentId && $invoice = Invoice::where('payment_id', $paymentId)->first()) {
            return $invoice;
        }

        $reference = $payment['order_reference'] ?? null;

        return $reference ? Invoice::where('order_reference', $reference)->first() : null;
    }

    protected function paymentIdOf(array $payment): ?string
    {
        // A webhook says `payment_id`; a fetched payment says `id`.
        $id = $payment['payment_id'] ?? $payment['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /** Statuses that take money back out of a settled invoice. */
    protected function reversesAPayment(?string $status): bool
    {
        return in_array($status, ['refunded', 'partially_refunded', 'disputed'], true);
    }

    protected function nextPeriodEnd(Subscription $subscription, CarbonInterface $from): CarbonInterface
    {
        return $subscription->billing_cycle->advance(Carbon::instance($from));
    }

    protected function fireUpdated(Subscription $subscription): void
    {
        SubscriptionUpdated::dispatch($subscription->fresh());
    }
}
