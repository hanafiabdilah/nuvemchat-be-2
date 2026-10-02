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
use App\Exceptions\UpstreamServiceException;
use App\Exceptions\UserFacingException;
use App\Models\Admin;
use App\Models\ApiwaySubscription;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\SavedCard;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TrainedAgentHire;
use App\Services\Billing\Gateways\AuthorisesRecurringSeparately;
use App\Services\Billing\Gateways\BillingGateway;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\Gateways\HoldsRecurringAuthorisations;
use App\Services\Billing\Gateways\OpensCardCheckouts;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Credits\CreditService;
use App\Services\Market\MarketBillingMethods;
use App\Services\Market\MarketDocuments;
use App\Services\TrainedAgent\TrainedAgentService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        protected BillingGateways $gateways,
        protected SubscriptionGate $gate,
        protected BillingNotifier $notifier,
        protected SavedCardService $savedCards,
        protected PlanChange $planChange,
    ) {}

    /**
     * How long a Pix instruction (or a hosted checkout link) stays payable.
     *
     * ⚠️ Set explicitly, and it matters more than it used to. Neither the
     * payment service nor a hosted checkout can be cancelled once issued, so
     * this window is the only thing that eventually kills a QR the customer
     * walked away from. Cancelling now stops us showing it and stops it
     * counting; it does not stop it being payable. If somebody pays anyway the
     * webhook honours it (see applyPaymentUpdate), which is the right answer —
     * the money arrived.
     *
     * Not shortened to minutes on purpose: someone paying a monthly plan opens
     * their bank app, and a code that expires while they are doing that turns
     * our gap into their failed payment.
     */
    protected const PIX_WINDOW_HOURS = 24;

    /**
     * How long a card form bound to a gateway payment stays usable (dLocal Go
     * SmartFields). Long enough to find the card in a wallet; the gateway
     * payment is given the same lifetime so both ends expire together.
     */
    protected const CARD_CHECKOUT_MINUTES = 60;

    /**
     * Subscribe a tenant to a plan via card (recurring), Pix, or a hosted
     * checkout.
     *
     * @param  array{card_token?:string, provider?:string, payer_email:string}  $opts
     */
    public function subscribe(Tenant $tenant, Plan $plan, PaymentMethod $method, array $opts): Subscription
    {
        // Refused here rather than priced at the platform's own number: a plan
        // with no price for this country was never offered to this customer,
        // and charging them anything for it would be a price nobody set.
        $price = $plan->priceForMarket($tenant->market_code);

        if ($price === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => __('This plan is not available in your country.'),
            ]);
        }

        // ⚠️ Enforced here, not only in the browser. Which methods a plan can
        // be paid with is no longer a per-plan checkbox — it is whatever the
        // gateway that bills this workspace can take today, and never Pix
        // outside the country that has it.
        if ($method !== PaymentMethod::Manual && ! $this->offers($tenant, $method)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'method' => __('This plan cannot be paid for this way in your country.'),
            ]);
        }

        // Upgrade, downgrade or a first plan — decided once, here, by the same
        // rule the checkout drew its numbers from (see PlanChange).
        $quote = $this->planChange->quote($tenant, $plan, $method);

        if ($quote['type'] === PlanChange::DOWNGRADE) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => __('This plan costs less than yours, so it starts when the period you already paid for ends. Schedule the change instead of paying now.'),
            ]);
        }

        if ($quote['type'] === PlanChange::CURRENT) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => __('This is already your plan.'),
            ]);
        }

        $opts['quote'] = $quote;

        return match ($method) {
            PaymentMethod::Card => $this->subscribeWithCard($tenant, $plan, $opts),
            PaymentMethod::Pix, PaymentMethod::Checkout => $this->subscribePerCycle($tenant, $plan, $method, $opts),
            PaymentMethod::Manual => throw new \InvalidArgumentException('Use grantManual for manual subscriptions.'),
        };
    }

    // --- What can be charged ---------------------------------------------

    /**
     * The raw method list of the gateway that would bill this workspace now.
     *
     * Cached for a minute per gateway and currency: cheap enough to ask on
     * every checkout render, and the whole point is that the answer changes.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentMethodsFor(Tenant $tenant): array
    {
        $gateway = $this->gateways->forTenant($tenant);
        $currency = $tenant->currency();

        return Cache::remember(
            "billing:payment-methods:{$gateway->name()}:{$currency}",
            now()->addMinute(),
            fn () => $gateway->paymentMethods($currency),
        );
    }

    /**
     * The methods this workspace can actually be sold, or null when the
     * gateway could not be asked.
     *
     * Card only where some gateway can charge it unattended (otherwise it is a
     * single charge dressed up as a subscription), Pix only where the rail
     * exists, a hosted checkout wherever the gateway offers one.
     *
     * @return list<string>|null
     */
    public function offeredMethods(Tenant $tenant): ?array
    {
        try {
            $methods = $this->paymentMethodsFor($tenant);
        } catch (\Throwable $e) {
            Log::warning('Could not read the payment methods', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->offeredFrom($tenant, $methods);
    }

    /**
     * @param  list<array<string, mixed>>  $methods
     * @return list<string>
     */
    public function offeredFrom(Tenant $tenant, array $methods): array
    {
        $offered = [];

        foreach ($methods as $method) {
            $name = $method['method'] ?? null;

            $ok = match ($name) {
                'card' => (bool) ($method['merchant_initiated_cards'] ?? false),
                'pix' => MarketBillingMethods::has($tenant->market_code, 'pix'),
                'checkout' => true,
                default => false,
            };

            if ($ok && ! in_array($name, $offered, true)) {
                $offered[] = $name;
            }
        }

        return $offered;
    }

    /**
     * ⚠️ Unknown counts as yes. The two failures are not equal: refusing a
     * checkout that would have worked, because the gateway blinked, stops a
     * customer from paying us — while letting one through that cannot work
     * ends at the gateway, with translated copy. Pix outside Brazil is the one
     * thing refused even blind: that is a fact about the country.
     */
    protected function offers(Tenant $tenant, PaymentMethod $method): bool
    {
        if ($method === PaymentMethod::Pix && ! MarketBillingMethods::has($tenant->market_code, 'pix')) {
            return false;
        }

        $offered = $this->offeredMethods($tenant);

        return $offered === null || in_array($method->value, $offered, true);
    }

    /** The card-session answer of whichever gateway would bill this workspace. */
    public function cardSession(Tenant $tenant): array
    {
        return $this->gateways->forTenant($tenant)->cardSession($tenant);
    }

    /**
     * Open the gateway payment a card form is bound to (dLocal Go SmartFields),
     * before the card is typed.
     *
     * ⚠️ Nothing local is written: no subscription, no invoice. The form opens
     * when the card tile is shown, and creating a pending subscription at that
     * moment would move `current_subscription_id` off a live plan just because
     * someone looked at the checkout. What is needed later is held in the cache
     * under the checkout token, scoped to this workspace, and read back once by
     * subscribe(). An abandoned form leaves only a gateway payment that expires.
     *
     * @return array{checkout_token: string, expires_at: string}
     */
    public function openCardCheckout(Tenant $tenant, Plan $plan, ?string $payerEmail = null): array
    {
        $price = $plan->priceForMarket($tenant->market_code);

        if ($price === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => __('This plan is not available in your country.'),
            ]);
        }

        if (! $this->offers($tenant, PaymentMethod::Card)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'method' => __('This plan cannot be paid for this way in your country.'),
            ]);
        }

        $gateway = $this->gateways->forTenant($tenant);

        if (! $gateway instanceof OpensCardCheckouts) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'method' => __('This plan cannot be paid for this way in your country.'),
            ]);
        }

        $this->assertBillable($tenant);

        $quote = $this->planChange->quote($tenant, $plan, PaymentMethod::Card);

        if (in_array($quote['type'], [PlanChange::DOWNGRADE, PlanChange::CURRENT], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => __('This plan is not charged now.'),
            ]);
        }

        $currency = $price->currency ?: ($plan->currency ?: $tenant->currency());
        $reference = 'pingly-card-'.$tenant->id.'-'.Str::lower(Str::random(16));
        $expiresAt = now()->addMinutes(self::CARD_CHECKOUT_MINUTES);

        $opened = $gateway->openCardCheckout([
            'order_reference' => $reference,
            // The upgrade's charge, credit already off — the form is bound to it.
            'amount' => $quote['charge_now_cents'],
            'currency' => $currency,
            'description' => "Assinatura {$plan->name}",
            'customer' => $this->customerPayload($tenant, $payerEmail),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        Cache::put($this->cardCheckoutKey($tenant, $opened['checkout_token']), [
            'plan_id' => $plan->id,
            'plan_price_cents' => $price->amount_cents,
            'amount_cents' => $quote['charge_now_cents'],
            // The credit frozen at the moment the form opened. It only shrinks
            // as time passes, so honouring the opened number is never a loss
            // the customer can feel — and re-pricing under a typed card is.
            'quote_type' => $quote['type'],
            'replaces_subscription_id' => $quote['current']['subscription_id'] ?? null,
            'discount_cents' => $quote['discount_cents'],
            'balance_credit_cents' => $quote['balance_credit_cents'],
            'currency' => $currency,
            'order_reference' => $reference,
            'payment_id' => $opened['payment']['id'] ?? null,
            'gateway' => $gateway->name(),
        ], $expiresAt);

        return [
            'checkout_token' => $opened['checkout_token'],
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    protected function cardCheckoutKey(Tenant $tenant, string $checkoutToken): string
    {
        return "billing:card-checkout:{$tenant->id}:".hash('sha256', $checkoutToken);
    }

    // --- Subscribing ---------------------------------------------------------

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
     *
     * Direct billing in Brazil answers this with a Mercado Pago preapproval:
     * the "instrument" is the preapproval, and Mercado Pago runs every cycle
     * after this one itself (see applyRecurringCharge()).
     */
    protected function subscribeWithCard(Tenant $tenant, Plan $plan, array $opts): Subscription
    {
        $this->assertBillable($tenant);

        $gateway = $this->gateways->forTenant($tenant);

        if ($gateway instanceof OpensCardCheckouts) {
            return $this->subscribeWithOpenedCard($tenant, $plan, $gateway, $opts);
        }

        if ($gateway instanceof AuthorisesRecurringSeparately) {
            return $this->subscribeWithCardThenMandate($tenant, $plan, $gateway, $opts);
        }

        // A kept card (Mercado Pago): the token was minted from its id and the
        // CVV typed now, and the payer has to be the customer the card belongs to.
        /** @var SavedCard|null $card */
        $card = $opts['saved_card'] ?? null;
        $payerEmail = $card?->customer_email ?: ($opts['payer_email'] ?? null);
        $quote = $opts['quote'] ?? null;
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Card, $gateway->name(), $quote, deferSwitch: true);
        $periodEnd = $this->nextPeriodEnd($subscription, now());
        $invoice = $this->firstCardInvoice($subscription, $plan, $gateway->name(), $quote, $periodEnd);

        try {
            $response = $gateway->createPayment([
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
                // Only read by a gateway that renews on its own schedule.
                'recurring' => $plan->billing_cycle->mercadoPagoFrequency(),
                'customer' => $this->customerPayload($tenant, $payerEmail),
                'metadata' => ['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            // The row exists before the call because its reference goes in the
            // payload; a refusal must not strand it looking payable.
            $this->markInvoiceFailed($invoice, 'subscribe_card', $e);
            $this->abandonDeferredSubscription($subscription);

            if ($card && ($opts['discard_card_on_failure'] ?? false)) {
                $this->savedCards->discardIfNeverUsed($card);
            }

            throw $e;
        }

        $payment = $response['data'] ?? [];

        $invoice->update([
            'payment_id' => isset($payment['id']) ? (string) $payment['id'] : null,
        ]);

        $this->rememberInstrument($subscription, $payment);

        if ($card) {
            in_array($payment['status'] ?? null, ['paid', 'pending', 'unknown'], true)
                ? $this->savedCards->markUsed($card)
                : (($opts['discard_card_on_failure'] ?? false) ? $this->savedCards->discardIfNeverUsed($card) : null);
        }

        if (($payment['status'] ?? null) === 'paid') {
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
            $this->activate($subscription, $periodEnd);
        } else {
            // Declined, or the rare `unknown` where the gateway never answered.
            // Neither activates anything; `unknown` resolves by itself and its
            // webhook lands on this same invoice through order_reference.
            if (in_array($payment['status'] ?? null, ['unknown', 'pending'], true)) {
                $invoice->update(['status' => InvoiceStatus::Pending]);
            } else {
                $this->markInvoiceFailed($invoice, 'subscribe_card', payment: $payment);
                $this->abandonDeferredSubscription($subscription);
            }
        }

        $this->fireUpdated($subscription);

        return $subscription->fresh();
    }

    /**
     * Card on a gateway that sets up renewals after the first payment (Mercado
     * Pago in Brazil — see AuthorisesRecurringSeparately for why).
     *
     * 1. The first cycle is a plain `/v1/payments` charge on the saved card,
     *    with the first of the two tokens the browser minted. This is the call
     *    that decides the purchase: paid activates the plan, refused leaves
     *    the workspace exactly as it was.
     * 2. Only then is the second token turned into a preapproval starting when
     *    the paid period ends. Its refusal does not undo the purchase — the
     *    plan is paid for — it leaves the subscription without an instrument,
     *    the state a card that stopped working is already in (renewal falls to
     *    past_due → grace, and the customer is asked for a card). The reason is
     *    kept on the invoice (`meta.mandate_failure`) for the Back Office.
     *
     * A card is always kept for a subscription: it is what the renewals run on.
     */
    protected function subscribeWithCardThenMandate(
        Tenant $tenant,
        Plan $plan,
        BillingGateway&AuthorisesRecurringSeparately $gateway,
        array $opts,
    ): Subscription {
        /** @var SavedCard|null $card */
        $card = $opts['saved_card'] ?? null;
        $chargeToken = (string) ($opts['card_token'] ?? '');
        $mandateToken = (string) ($opts['mandate_token'] ?? '');

        if (! $card || $card->gateway !== $gateway->name() || $chargeToken === '' || $mandateToken === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'saved_card_id' => __('Choose a card and enter its security code.'),
            ]);
        }

        $quote = $opts['quote'] ?? null;
        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Card, $gateway->name(), $quote, deferSwitch: true);
        $periodEnd = $this->nextPeriodEnd($subscription, now());
        $invoice = $this->firstCardInvoice($subscription, $plan, $gateway->name(), $quote, $periodEnd);

        try {
            $response = $gateway->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => 'card',
                'once' => true,
                'card_token' => $chargeToken,
                'card_brand' => $card->brand,
                'card_issuer_id' => $card->issuer_id,
                'customer_id' => $card->customer_id,
                'description' => "Assinatura {$plan->name}",
                'customer' => $this->customerPayload($tenant, $card->customer_email),
                'metadata' => ['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            $this->markInvoiceFailed($invoice, 'subscribe_card', $e);
            $this->abandonDeferredSubscription($subscription);

            if ($opts['discard_card_on_failure'] ?? false) {
                $this->savedCards->discardIfNeverUsed($card);
            }

            throw $e;
        }

        $payment = $response['data'] ?? [];
        $status = $payment['status'] ?? null;

        if (filled($payment['id'] ?? null)) {
            $invoice->update(['payment_id' => (string) $payment['id']]);
        }

        if ($status === 'paid') {
            $this->savedCards->markUsed($card);
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
            $this->activate($subscription, $periodEnd);
            $this->authoriseRenewals($subscription->fresh(), $gateway, $invoice, $card, $mandateToken, $periodEnd);
        } elseif (in_array($status, ['pending', 'unknown'], true)) {
            // In review (`in_process`): the webhook settles the invoice. The
            // mandate token does not live that long, so the renewals are not
            // set up — the customer is asked for a card before the period ends.
            $this->savedCards->markUsed($card);
            $invoice->update([
                'status' => InvoiceStatus::Pending,
                'meta' => array_merge($invoice->meta ?? [], [
                    'mandate_failure' => $this->failureRecord($invoice, 'mandate', message: 'First charge still in review; renewals not authorised.'),
                ]),
            ]);
        } else {
            $this->markInvoiceFailed($invoice, 'subscribe_card', payment: $payment);
            $this->abandonDeferredSubscription($subscription);

            if ($opts['discard_card_on_failure'] ?? false) {
                $this->savedCards->discardIfNeverUsed($card);
            }
        }

        $this->fireUpdated($subscription);

        return $subscription->fresh();
    }

    /**
     * The second half of subscribeWithCardThenMandate(): the standing
     * authorisation for every cycle after the one just paid. Never throws —
     * the purchase already succeeded.
     */
    protected function authoriseRenewals(
        Subscription $subscription,
        AuthorisesRecurringSeparately $gateway,
        Invoice $invoice,
        SavedCard $card,
        string $mandateToken,
        CarbonInterface $periodEnd,
    ): void {
        try {
            $instrumentId = $gateway->authoriseRecurring([
                'amount' => (int) $subscription->price_cents,
                'currency' => $subscription->currency ?: 'BRL',
                'card_token' => $mandateToken,
                'start_date' => $periodEnd,
                'recurring' => $subscription->plan->billing_cycle->mercadoPagoFrequency(),
                'description' => "Assinatura {$subscription->plan->name}",
                'customer' => $this->customerPayload($subscription->tenant, $card->customer_email),
                'subscription_id' => $subscription->id,
            ], 'mandate-'.$invoice->idempotency_key);

            $subscription->forceFill(['payment_instrument_id' => $instrumentId])->save();
        } catch (\Throwable $e) {
            Log::warning('Card subscription paid, but its renewals could not be authorised', [
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $invoice->update([
                'meta' => array_merge($invoice->meta ?? [], [
                    'mandate_failure' => $this->failureRecord($invoice, 'mandate', $e),
                ]),
            ]);
        }
    }

    /**
     * The invoice for a card subscription's first cycle. Created before the
     * gateway call because its reference goes in the payload.
     */
    protected function firstCardInvoice(Subscription $subscription, Plan $plan, string $gateway, ?array $quote, CarbonInterface $periodEnd): Invoice
    {
        $first = $this->firstCharge($subscription, $quote);

        return Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'gateway' => $gateway,
            'amount_cents' => $first['amount_cents'],
            'proration_credit_cents' => $first['proration_credit_cents'],
            // From the subscription, which froze both halves of the price when
            // it was created — see createPendingSubscription().
            'currency' => $subscription->currency ?: ($plan->currency ?? 'BRL'),
            'period_start' => $subscription->current_period_start,
            'period_end' => $periodEnd,
            'order_reference' => $this->orderReference($subscription, $subscription->current_period_start),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /**
     * A refused card purchase that was waiting to replace a plan: closed, so
     * it never shows up as a pending change and the plan it would have
     * replaced simply carries on. A purchase that replaced nothing is left as
     * it always was (it is the workspace's only row).
     */
    protected function abandonDeferredSubscription(Subscription $subscription): void
    {
        if (! $subscription->replaces_subscription_id) {
            return;
        }

        $subscription->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
    }

    /**
     * Card on a gateway whose form is bound to a payment opened beforehand
     * (dLocal Go SmartFields): confirm that payment with the card token and
     * keep the card for the cycles after this one.
     *
     * Three outcomes, like any first card charge, plus one: paid activates,
     * refused fails the invoice, and **pending** is the issuer asking for 3-D
     * Secure — the invoice keeps the authentication link in `checkout_url`, the
     * page sends the customer there, and the webhook settles it. The card is
     * kept in that case too: it is being authenticated, not refused.
     *
     * ⚠️ The opened checkout is read once (`Cache::pull`). A card token is
     * single-use and a refused confirm cannot be assumed retryable on the same
     * payment, so a second attempt opens a new form rather than reusing this one.
     */
    protected function subscribeWithOpenedCard(Tenant $tenant, Plan $plan, BillingGateway $gateway, array $opts): Subscription
    {
        $checkoutToken = (string) ($opts['checkout_token'] ?? '');
        $opened = $checkoutToken === '' ? null : Cache::pull($this->cardCheckoutKey($tenant, $checkoutToken));
        $price = $plan->priceForMarket($tenant->market_code);

        // Expired, from another workspace, for another plan, or opened at a
        // price that has since changed: the form is not the charge it claims
        // to be, so the customer types the card again on a fresh one.
        $quote = $opts['quote'] ?? null;

        // The plan being replaced has changed under the form (it lapsed, an
        // upgrade elsewhere went through): the credit it was opened with is no
        // longer the credit that applies.
        $sameChange = is_array($opened)
            && ($opened['quote_type'] ?? PlanChange::NEW) === ($quote['type'] ?? PlanChange::NEW)
            && (int) ($opened['replaces_subscription_id'] ?? 0) === (int) ($quote['current']['subscription_id'] ?? 0);

        if (! is_array($opened)
            || ! $sameChange
            || (int) $opened['plan_id'] !== (int) $plan->id
            || ($opened['gateway'] ?? null) !== $gateway->name()
            || $price === null
            || (int) ($opened['plan_price_cents'] ?? $opened['amount_cents']) !== (int) $price->amount_cents) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'checkout_token' => __('This card form has expired. Please enter your card again.'),
            ]);
        }

        if ($quote !== null) {
            $quote['discount_cents'] = (int) ($opened['discount_cents'] ?? 0);
            $quote['balance_credit_cents'] = (int) ($opened['balance_credit_cents'] ?? 0);
            $quote['charge_now_cents'] = (int) $opened['amount_cents'];
        }

        $subscription = $this->createPendingSubscription($tenant, $plan, PaymentMethod::Card, $gateway->name(), $quote);
        $periodEnd = $this->nextPeriodEnd($subscription, now());

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'gateway' => $gateway->name(),
            'amount_cents' => $opened['amount_cents'],
            'proration_credit_cents' => ($opened['discount_cents'] ?? 0) ?: null,
            'currency' => $opened['currency'],
            'period_start' => $subscription->current_period_start,
            'period_end' => $periodEnd,
            // The reference the gateway payment was opened with, so its
            // notification lands here.
            'order_reference' => $opened['order_reference'],
            'payment_id' => $opened['payment_id'],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            /** @var OpensCardCheckouts $gateway */
            $payment = $gateway->confirmCardCheckout(
                $checkoutToken,
                (string) ($opts['card_token'] ?? ''),
                $this->customerPayload($tenant, $opts['payer_email'] ?? null),
            );
        } catch (\Throwable $e) {
            $this->markInvoiceFailed($invoice, 'subscribe_card', $e);

            throw $e;
        }

        if (filled($payment['id'] ?? null)) {
            $invoice->update(['payment_id' => (string) $payment['id']]);
        }

        $status = $payment['status'] ?? null;

        if (in_array($status, ['paid', 'pending'], true)) {
            $this->rememberInstrument($subscription, $payment);
        }

        if ($status === 'paid') {
            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
            $this->activate($subscription, $periodEnd);
        } elseif (in_array($status, ['pending', 'unknown'], true)) {
            $invoice->update(['checkout_url' => $payment['instructions']['redirect_url'] ?? null]);
        } else {
            $this->markInvoiceFailed($invoice, 'subscribe_card', payment: $payment);
        }

        $this->fireUpdated($subscription);

        return $subscription->fresh();
    }

    /**
     * Pix or a hosted checkout: a pending subscription + the first charge the
     * customer pays by hand. Becomes active once that charge settles.
     */
    protected function subscribePerCycle(Tenant $tenant, Plan $plan, PaymentMethod $method, array $opts): Subscription
    {
        $this->assertBillable($tenant);

        // Starts past_due (createPendingSubscription); becomes active once paid.
        $quote = $opts['quote'] ?? null;
        $subscription = $this->createPendingSubscription(
            $tenant,
            $plan,
            $method,
            $this->gateways->forTenant($tenant)->name(),
            $quote,
        );

        $this->createCycleInvoice($subscription, $method, $opts['payer_email'] ?? null, $this->firstCharge($subscription, $quote));
        $this->fireUpdated($subscription);

        return $subscription;
    }

    /**
     * Create (or refresh) a pending Pix invoice for a subscription's next period.
     *
     * Kept under its old name for the Pix refresh endpoint; the method is
     * chosen by renewalMethodFor() when the subscription was not paid by Pix.
     */
    public function createPixInvoice(Subscription $subscription, ?string $payerEmail = null): Invoice
    {
        return $this->createCycleInvoice($subscription, $this->renewalMethodFor($subscription), $payerEmail);
    }

    /**
     * Which per-cycle method the next invoice should use.
     *
     * The subscription's own, unless the gateway billing this workspace today
     * no longer offers it — flipping PAYMENT_METHOD, or a provider switched
     * off — in which case the other per-cycle method it does offer. Better a
     * payable link in a different shape than a renewal nobody can pay.
     */
    public function renewalMethodFor(Subscription $subscription): PaymentMethod
    {
        $own = $subscription->payment_method?->isPaidPerCycle() ? $subscription->payment_method : PaymentMethod::Pix;
        $offered = $subscription->tenant ? $this->offeredMethods($subscription->tenant) : null;

        if ($offered === null || in_array($own->value, $offered, true)) {
            return $own;
        }

        foreach ([PaymentMethod::Pix, PaymentMethod::Checkout] as $candidate) {
            if (in_array($candidate->value, $offered, true)) {
                return $candidate;
            }
        }

        return $own;
    }

    /**
     * Issue the invoice the customer pays by hand for a subscription's next
     * period: a Pix QR, or a link to the gateway's hosted checkout.
     */
    public function createCycleInvoice(
        Subscription $subscription,
        PaymentMethod $method,
        ?string $payerEmail = null,
        ?array $first = null,
    ): Invoice {
        $tenant = $subscription->tenant;
        $this->assertBillable($tenant);

        $gateway = $this->gateways->forTenant($tenant);
        $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end->copy()
            : now();
        // A renewal bills the period it starts at those terms — the scheduled
        // downgrade when that period is the first one after it.
        $terms = PlanChange::termsFor($subscription, $periodStart);
        $plan = $terms['plan'] ?? $subscription->plan;
        $periodEnd = $terms['cycle']->advance(Carbon::instance($periodStart));
        $expiresAt = now()->addHours(self::PIX_WINDOW_HOURS);

        $invoice = Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Pending,
            'payment_method' => $method,
            'gateway' => $gateway->name(),
            'amount_cents' => $first['amount_cents'] ?? $terms['price_cents'],
            'proration_credit_cents' => $first['proration_credit_cents'] ?? null,
            // The snapshot taken when the workspace subscribed, not today's
            // plan: the price is frozen, so its unit has to be frozen with it.
            'currency' => $subscription->currency ?: ($plan?->currency ?? 'BRL'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $periodEnd?->toDateString(),
            'order_reference' => $this->orderReference($subscription, $periodStart),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $response = $gateway->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => $method->value,
                'description' => "Assinatura {$plan?->name} — fatura #{$invoice->id}",
                'expires_at' => $expiresAt->toIso8601String(),
                'return_url' => DirectBillingConfig::returnUrl('/billing'),
                'customer' => $this->customerPayload($tenant, $payerEmail),
                'metadata' => ['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            // The row has to exist before the call (its reference goes in the
            // payload), so a rejection would otherwise strand a pending invoice
            // that looks payable but carries no QR — and the $hasOpen guard in
            // billing:pix-generate would then refuse to issue a real one.
            $this->markInvoiceFailed($invoice, 'cycle_charge', $e);

            throw $e;
        }

        $this->applyInstructions($invoice, $response['data'] ?? [], $expiresAt);

        return $invoice->fresh();
    }

    /**
     * Create a payable charge that tops up a workspace's prepaid balance.
     *
     * Pix where the country has it, the gateway's hosted checkout elsewhere.
     * Never a card here: a top-up is "put R$50 in, whenever you feel like it",
     * which no stored-instrument schedule describes, and this product has
     * nowhere to collect a card outside the subscription checkout.
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
    public function createCreditTopupInvoice(Tenant $tenant, int $amountCents, ?string $payerEmail = null): Invoice
    {
        $this->assertBillable($tenant);

        $method = $this->topupMethodFor($tenant);

        // ⚠️ Refused here, where the sentence can say so. Without it an
        // Indonesian workspace once got a real invoice, then a gateway refusal,
        // then copy telling them to try another payment method — one this
        // endpoint never offered a choice about.
        if ($method === null) {
            throw new UserFacingException(
                'Ainda não há meio de pagamento disponível no seu país para adicionar saldo.',
                422,
                'topup_unavailable_in_market',
            );
        }

        $gateway = $this->gateways->forTenant($tenant);
        $expiresAt = now()->addHours(self::PIX_WINDOW_HOURS);

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'purpose' => InvoicePurpose::CreditTopup,
            'status' => InvoiceStatus::Pending,
            'payment_method' => $method,
            'gateway' => $gateway->name(),
            'amount_cents' => $amountCents,
            // The balance this tops up is in the workspace's own currency, so
            // the charge that fills it has to be too.
            'currency' => $tenant->currency(),
            'due_date' => $expiresAt->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // A top-up has no period, so the invoice id is the whole identity —
        // unlike a subscription, where the reference must carry the cycle.
        $invoice->update(['order_reference' => "pingly-topup-{$invoice->id}"]);

        try {
            $response = $gateway->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => $method->value,
                'description' => "Créditos — fatura #{$invoice->id}",
                'expires_at' => $expiresAt->toIso8601String(),
                'return_url' => DirectBillingConfig::returnUrl('/billing?tab=credits'),
                'customer' => $this->customerPayload($tenant, $payerEmail),
                'metadata' => ['tenant_id' => $tenant->id, 'purpose' => 'credit_topup'],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            $this->markInvoiceFailed($invoice, 'topup', $e);

            throw $e;
        }

        $this->applyInstructions($invoice, $response['data'] ?? [], $expiresAt);

        return $invoice->fresh();
    }

    /**
     * Top up the balance with a saved card (Mercado Pago): one charge, not a
     * subscription, on a token minted from the card id and the CVV typed now.
     *
     * Synchronous like any card. Paid credits the balance here, through the same
     * settlement the webhook uses (so a late notification is a no-op); pending
     * (`in_process`, a manual review) is settled by that webhook later; refused
     * fails the invoice and — for a card added for this very charge — takes the
     * card back out of the list.
     *
     * `$keepCard = false` is a new card the customer chose not to keep: a one-off
     * charge needs the card on the Mercado Pago customer (that is where its
     * brand and issuer come from), so it is kept for the charge and removed
     * right after, whatever the outcome.
     */
    public function createCreditTopupCardPayment(
        Tenant $tenant,
        int $amountCents,
        SavedCard $card,
        string $cardToken,
        bool $discardCardOnFailure = false,
        bool $keepCard = true,
    ): Invoice {
        $this->assertBillable($tenant);

        // Only a card that has never paid for anything can be dropped after the
        // charge — "don't keep it" is about the card typed now, not one the
        // workspace already relies on.
        $dropAfter = ! $keepCard && $card->last_used_at === null;

        $gateway = $this->gateways->forTenant($tenant);

        if ($gateway->name() !== $card->gateway || $this->savedCards->gatewayFor($tenant) === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'method' => __('This plan cannot be paid for this way in your country.'),
            ]);
        }

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'purpose' => InvoicePurpose::CreditTopup,
            'status' => InvoiceStatus::Pending,
            'payment_method' => PaymentMethod::Card,
            'gateway' => $gateway->name(),
            'amount_cents' => $amountCents,
            'currency' => $tenant->currency(),
            'due_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $invoice->update(['order_reference' => "pingly-topup-{$invoice->id}"]);

        try {
            $response = $gateway->createPayment([
                'order_reference' => $invoice->order_reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => 'card',
                'once' => true,
                'card_token' => $cardToken,
                'card_brand' => $card->brand,
                'card_issuer_id' => $card->issuer_id,
                'customer_id' => $card->customer_id,
                'description' => "Créditos — fatura #{$invoice->id}",
                'customer' => $this->customerPayload($tenant, $card->customer_email),
                'metadata' => ['tenant_id' => $tenant->id, 'purpose' => 'credit_topup'],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            $this->markInvoiceFailed($invoice, 'topup_card', $e);

            if ($discardCardOnFailure || $dropAfter) {
                $this->savedCards->discardIfNeverUsed($card);
            }

            throw $e;
        }

        $payment = $response['data'] ?? [];
        $status = $payment['status'] ?? null;

        if ($dropAfter) {
            $this->savedCards->remove($card);
        }

        if (filled($payment['id'] ?? null)) {
            $invoice->update(['payment_id' => (string) $payment['id']]);
        }

        if (! $dropAfter && in_array($status, ['paid', 'pending', 'unknown'], true)) {
            $this->savedCards->markUsed($card);
        }

        if ($status === 'paid') {
            $this->applyAssetPaymentUpdate($invoice->fresh(), $payment['id'] ?? null, 'paid');
        } elseif (! in_array($status, ['pending', 'unknown'], true)) {
            $this->markInvoiceFailed($invoice, 'topup_card', payment: $payment);

            if ($discardCardOnFailure && ! $dropAfter) {
                $this->savedCards->discardIfNeverUsed($card);
            }
        }

        return $invoice->fresh();
    }

    /** @deprecated The top-up is no longer Pix-only; use createCreditTopupInvoice(). */
    public function createCreditTopupPixInvoice(Tenant $tenant, int $amountCents, ?string $payerEmail = null): Invoice
    {
        return $this->createCreditTopupInvoice($tenant, $amountCents, $payerEmail);
    }

    /**
     * Pix first, then a hosted checkout. Unknown (the gateway could not be
     * asked) counts as Pix where the rail exists — see offers() for why blind
     * is not the same as no.
     */
    protected function topupMethodFor(Tenant $tenant): ?PaymentMethod
    {
        $hasPixRail = MarketBillingMethods::has($tenant->market_code, 'pix');
        $offered = $this->offeredMethods($tenant);

        if ($offered === null) {
            return $hasPixRail ? PaymentMethod::Pix : null;
        }

        if (in_array('pix', $offered, true)) {
            return PaymentMethod::Pix;
        }

        return in_array('checkout', $offered, true) ? PaymentMethod::Checkout : null;
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
     * @return Invoice|null null when there was nothing to charge.
     */
    public function chargeRenewal(Subscription $subscription): ?Invoice
    {
        if ($subscription->payment_method !== PaymentMethod::Card || ! $subscription->payment_instrument_id) {
            return null;
        }

        // The gateway that holds the card — not whatever PAYMENT_METHOD says
        // today. A card stored at the payment service is meaningless anywhere
        // else, and one held by a Mercado Pago preapproval renews itself.
        $gateway = $this->gateways->forSubscription($subscription);

        if ($gateway->renewsItself($subscription->payment_instrument_id)) {
            return null;
        }

        $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end->copy()
            : now();
        $terms = PlanChange::termsFor($subscription, $periodStart);
        $periodEnd = $terms['cycle']->advance(Carbon::instance($periodStart));
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
            'gateway' => $gateway->name(),
            'amount_cents' => $terms['price_cents'],
            // ⚠️ The snapshot, never the live plan. Reading the currency off
            // the plan while the amount comes from the subscription meant a
            // plan edited from one currency to another silently re-denominated
            // every subscription already on it: same integer, different money,
            // on the next renewal.
            'currency' => $subscription->currency ?: ($subscription->plan?->currency ?? 'BRL'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'order_reference' => $reference,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        try {
            $response = $gateway->createPayment([
                'order_reference' => $reference,
                'amount' => $invoice->amount_cents,
                'currency' => $invoice->currency,
                'payment_method' => 'card',
                'instrument_id' => $subscription->payment_instrument_id,
                // Nobody is at a screen: no security code, no 3-D Secure, and
                // the provider must be one that can take such a charge at all.
                'initiator' => 'merchant',
                'description' => 'Renovação '.($terms['plan']?->name ?? $subscription->plan?->name),
                'metadata' => ['tenant_id' => $subscription->tenant_id, 'subscription_id' => $subscription->id],
            ], $invoice->idempotency_key);
        } catch (\Throwable $e) {
            $this->markInvoiceFailed($invoice, 'card_renewal', $e);

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
     * @param  string|null  $gateway  Which gateway said so. Payment ids are only
     *                                unique within one gateway, so a direct
     *                                gateway's id must never match an invoice
     *                                made elsewhere.
     */
    public function applyPaymentUpdate(array $payment, ?string $gateway = null): void
    {
        $paymentId = $this->paymentIdOf($payment);
        $status = $payment['status'] ?? null;

        $invoice = $this->matchInvoice($payment, $gateway);

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

        DB::transaction(function () use ($invoice, $paymentId, $status, $payment) {
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
                in_array($status, ['declined', 'voided'], true) => $this->markInvoiceFailed($invoice, 'webhook', payment: $payment),
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
                in_array($status, ['declined', 'voided'], true) => $this->markInvoiceFailed($invoice, 'webhook'),
                default => $invoice->save(),
            };

            if ($status === 'paid') {
                $fresh = $invoice->fresh();

                match (true) {
                    $fresh->purpose === InvoicePurpose::TrainedAgentPurchase => app(TrainedAgentService::class)->handleInvoicePaid($fresh),
                    $fresh->purpose === InvoicePurpose::CreditTopup => app(CreditService::class)->creditTopup($fresh),
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
     * One cycle of a subscription the gateway renews on its own (a Mercado
     * Pago preapproval), reported by webhook.
     *
     * Two shapes arrive here and must not be confused. The *first* debit of a
     * new preapproval pays the cycle subscribeWithCard() already invoiced —
     * it is attached to that invoice, never billed as a second period. Every
     * later one is a renewal: a paid invoice for the next period, and the
     * subscription moved forward. Only approved debits count; a refused one is
     * retried by the gateway, and a period that runs out unpaid lapses through
     * billing:process-overdue like any other.
     */
    public function applyRecurringCharge(string $gateway, string $instrumentId, ?string $paymentId, ?string $status): void
    {
        $subscription = Subscription::where('gateway', $gateway)
            ->where('payment_instrument_id', $instrumentId)
            ->latest('id')
            ->first();

        if (! $subscription) {
            Log::warning('Recurring charge with no matching subscription', [
                'gateway' => $gateway,
                'instrument_id' => $instrumentId,
                'payment_id' => $paymentId,
            ]);

            return;
        }

        if ($status !== 'paid') {
            Log::info('Recurring charge not approved', [
                'subscription_id' => $subscription->id,
                'payment_id' => $paymentId,
                'status' => $status,
            ]);

            return;
        }

        // Exact idempotency: this debit already produced (or joined) an invoice.
        if ($paymentId && Invoice::where('gateway', $gateway)->where('payment_id', $paymentId)->exists()) {
            return;
        }

        DB::transaction(function () use ($subscription, $gateway, $paymentId) {
            $subscription = Subscription::lockForUpdate()->find($subscription->id);

            // The first debit: the invoice created at subscribe time is still
            // waiting for a payment id.
            $first = $subscription->invoices()
                ->where('gateway', $gateway)
                ->where('payment_method', PaymentMethod::Card->value)
                ->whereNull('payment_id')
                ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Pending->value])
                ->oldest('id')
                ->first();

            if ($first) {
                $wasPending = $first->status === InvoiceStatus::Pending;

                $first->update([
                    'payment_id' => $paymentId,
                    'status' => InvoiceStatus::Paid,
                    'paid_at' => $first->paid_at ?? now(),
                ]);

                if ($wasPending) {
                    $this->activate($subscription, $first->period_end ?? $this->nextPeriodEnd($subscription, now()));
                }

                $this->fireUpdated($subscription);

                return;
            }

            $periodStart = $subscription->current_period_end && $subscription->current_period_end->isFuture()
                ? $subscription->current_period_end->copy()
                : now();
            $terms = PlanChange::termsFor($subscription, $periodStart);
            $periodEnd = $terms['cycle']->advance(Carbon::instance($periodStart));

            Invoice::firstOrCreate(
                ['order_reference' => $this->orderReference($subscription, $periodStart)],
                [
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'status' => InvoiceStatus::Paid,
                    'payment_method' => PaymentMethod::Card,
                    'gateway' => $gateway,
                    'amount_cents' => $terms['price_cents'],
                    'currency' => $subscription->currency ?: 'BRL',
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'paid_at' => now(),
                    'payment_id' => $paymentId,
                    'idempotency_key' => (string) Str::uuid(),
                ],
            );

            $this->activate($subscription, $periodEnd);
            $this->fireUpdated($subscription);
        });
    }

    /**
     * The gateway changed a standing authorisation on its side — the customer
     * cancelled it in their Mercado Pago account, or it gave up after repeated
     * refusals. Treated exactly like an instrument that stopped working.
     *
     * `paused` is ignored: this platform pauses it itself on cancel-at-period-
     * end, and reading our own echo back as a failure would notify a customer
     * who simply asked to leave.
     */
    public function applyRecurringStateChange(string $gateway, string $instrumentId, ?string $state): void
    {
        if ($state !== 'cancelled') {
            return;
        }

        $matches = Subscription::where('gateway', $gateway)
            ->where('payment_instrument_id', $instrumentId)
            ->whereNotIn('status', [SubscriptionStatus::Cancelled->value, SubscriptionStatus::Suspended->value])
            ->exists();

        if ($matches) {
            $this->applyInstrumentUpdate(['instrument_id' => $instrumentId, 'status' => 'cancelled', 'reason' => 'gateway_cancelled']);
        }
    }

    /**
     * Tell the gateway about a standing authorisation, when there is one.
     *
     * Best-effort by design: the local state change is what the customer asked
     * for and must happen regardless. A failure is logged loudly, because the
     * price of missing it is a card charged for a plan somebody left.
     */
    protected function setRecurringState(Subscription $subscription, string $state): void
    {
        $instrumentId = $subscription->payment_instrument_id;

        if (! $instrumentId) {
            return;
        }

        $gateway = $this->gateways->forSubscription($subscription);

        if (! $gateway->renewsItself($instrumentId)) {
            return;
        }

        try {
            $gateway->setRecurringState($instrumentId, $state);
        } catch (\Throwable $e) {
            Log::error('Could not update a recurring authorisation at the gateway', [
                'subscription_id' => $subscription->id,
                'gateway' => $gateway->name(),
                'instrument_id' => $instrumentId,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);
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
                // ⚠️ The workspace's own money, not the column default. A comped
                // subscription is still denominated in something, and leaving it
                // to default('BRL') is why an Indonesian workspace granted a plan
                // by an admin read "R$" on its own billing page.
                'currency' => $tenant->currency(),
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

        // A preapproval would keep charging on its own schedule. Paused rather
        // than cancelled, because `cancelled` is terminal at Mercado Pago and
        // resume() has to be able to undo this.
        $this->setRecurringState($subscription, 'paused');

        $subscription->update([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
        ]);

        $this->fireUpdated($subscription);

        return $subscription;
    }

    /**
     * Undo `cancel()`. Symmetric with it for the same reason it exists: there
     * was nothing to tell the provider when cancelling (no preapproval to
     * revoke), so there is nothing to tell it here either — clearing the flag
     * before the renewal commands next read it is the whole operation.
     */
    public function resume(Subscription $subscription): Subscription
    {
        $this->setRecurringState($subscription, 'active');

        $subscription->update([
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
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

        $this->setRecurringState($subscription, 'cancelled');

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
        // Every charge the customer pays by hand: a Pix QR or a hosted link.
        $open = $subscription->invoices()
            ->where('status', InvoiceStatus::Pending->value)
            ->whereIn('payment_method', [PaymentMethod::Pix->value, PaymentMethod::Checkout->value])
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
                $gateway = $this->gateways->forInvoice($invoice);
                $payment = $gateway->getPayment($invoice->payment_id);

                if (($payment['status'] ?? null) === 'paid') {
                    $this->applyPaymentUpdate($payment, $gateway->name());

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

        // A stored instrument is only charged when we ask, and a suspended
        // subscription is never asked for — but a preapproval charges itself,
        // so that one has to be ended at the gateway.
        $this->setRecurringState($subscription, 'cancelled');

        $subscription->update(['status' => SubscriptionStatus::Suspended]);
        $this->gate->forget($subscription->tenant);
        $this->fireUpdated($subscription);
        $this->notifier->notify(NotificationType::SubscriptionSuspended, $subscription);
    }

    // --- Changing plan --------------------------------------------------------

    /**
     * The upgrade waiting for its first payment, if there is one.
     *
     * It is not the tenant's current subscription — the plan it replaces is,
     * until it is paid — so every screen that offers to pay or abandon it has
     * to ask for it by name.
     */
    public function pendingChangeFor(Tenant $tenant): ?Subscription
    {
        if (! $tenant->current_subscription_id) {
            return null;
        }

        return Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->where('replaces_subscription_id', $tenant->current_subscription_id)
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNull('current_period_end')
            ->latest('id')
            ->first();
    }

    /**
     * Abandon every upgrade that was started and never paid.
     *
     * Runs before any new checkout: two pending upgrades would each take over
     * the workspace the moment their own charge settled.
     */
    public function discardPendingChanges(Tenant $tenant): void
    {
        $pending = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('replaces_subscription_id')
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNull('current_period_end')
            ->when($tenant->current_subscription_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->get();

        foreach ($pending as $subscription) {
            try {
                $this->cancelPendingCheckout($subscription);
            } catch (PaymentAlreadySettledException) {
                // Paid in the seconds before: it has already taken over, which
                // is the right outcome for money that arrived.
            }
        }
    }

    /**
     * An upgrade's first charge settled: it becomes the workspace's plan and
     * the one it replaced ends.
     *
     * Only when the replaced plan is still the current one. If the workspace
     * moved on meanwhile (an admin grant, another plan paid), the late payment
     * is kept on its own row and logged rather than allowed to yank the
     * workspace onto a plan somebody already walked away from.
     */
    protected function completePlanSwitch(Subscription $subscription): void
    {
        if (! $subscription->replaces_subscription_id) {
            return;
        }

        $tenant = Tenant::find($subscription->tenant_id);

        if (! $tenant || (int) $tenant->current_subscription_id === (int) $subscription->id) {
            return;
        }

        if ((int) $tenant->current_subscription_id !== (int) $subscription->replaces_subscription_id) {
            Log::warning('Upgrade paid after the plan it replaced was no longer current', [
                'subscription_id' => $subscription->id,
                'replaces_subscription_id' => $subscription->replaces_subscription_id,
                'current_subscription_id' => $tenant->current_subscription_id,
            ]);

            return;
        }

        $old = Subscription::find($subscription->replaces_subscription_id);

        if ($old && $old->status !== SubscriptionStatus::Cancelled) {
            $old->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'scheduled_plan_id' => null,
                'scheduled_price_cents' => null,
                'scheduled_billing_cycle' => null,
                'scheduled_change_at' => null,
            ]);
        }

        $this->setCurrent($tenant, $subscription);

        $balance = (int) ($subscription->proration_balance_cents ?? 0);
        $planName = $subscription->plan?->name;

        // Gateway calls and the wallet write wait for the commit: the webhook
        // paths call this inside their transaction, and neither a cancelled
        // preapproval nor a balance credit can be rolled back with it.
        DB::afterCommit(function () use ($old, $tenant, $subscription, $balance, $planName) {
            if ($old) {
                try {
                    $this->voidOpenPixInvoices($old);
                } catch (\Throwable $e) {
                    Log::warning('Could not close the replaced plan\'s open charges', [
                        'subscription_id' => $old->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // ⚠️ A preapproval on the replaced plan would go on charging
                // the old price next to the new one.
                $this->setRecurringState($old, 'cancelled');
            }

            if ($balance > 0) {
                app(CreditService::class)->creditPlanChange(
                    $tenant,
                    $balance,
                    $subscription->id,
                    "Crédito do plano anterior na troca para {$planName}",
                    ['replaced_subscription_id' => $old?->id],
                );
            }
        });

        Log::info('Plan upgraded', [
            'tenant_id' => $tenant->id,
            'from_subscription_id' => $old?->id,
            'to_subscription_id' => $subscription->id,
            'balance_credit_cents' => $balance,
        ]);
    }

    /**
     * Schedule a downgrade for the end of what is already paid for.
     *
     * Same subscription row, new terms from `scheduled_change_at`: nothing is
     * charged now and nothing is taken away early. Choosing a cheaper plan on
     * a subscription set to end also keeps it — that is what the choice means.
     */
    public function schedulePlanChange(Tenant $tenant, Plan $plan): Subscription
    {
        $quote = $this->planChange->quote($tenant, $plan);

        if ($quote['type'] !== PlanChange::DOWNGRADE) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => __('Only a plan that costs less than yours is scheduled. This one is paid now.'),
            ]);
        }

        if ($quote['blocked_reason'] !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['plan_id' => $quote['blocked_reason']]);
        }

        /** @var Subscription $current */
        $current = $tenant->currentSubscription;
        $price = (int) $quote['plan']['price_cents'];

        // Gateway first: a preapproval left at the old amount would charge the
        // old price for the new plan, and that is the customer's money.
        $this->syncRecurringAmount($current, $price);

        $this->discardPendingChanges($tenant);

        if ($current->cancel_at_period_end) {
            $this->setRecurringState($current, 'active');
        }

        $current->update([
            'scheduled_plan_id' => $plan->id,
            'scheduled_price_cents' => $price,
            'scheduled_billing_cycle' => $plan->billing_cycle,
            'scheduled_change_at' => $current->current_period_end,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ]);

        // A renewal already issued at the old price for the cycle the change
        // starts at is closed, so the next run issues it at the new one.
        $this->voidCycleChargesFrom($current, $current->scheduled_change_at);

        $this->fireUpdated($current);

        return $current->fresh();
    }

    /** Keep the current plan after all: drop a scheduled downgrade. */
    public function cancelScheduledChange(Tenant $tenant): ?Subscription
    {
        $current = $tenant->currentSubscription;

        if (! $current || ! $current->hasScheduledChange()) {
            return $current;
        }

        $this->syncRecurringAmount($current, (int) $current->price_cents);

        $from = $current->scheduled_change_at;

        $current->update([
            'scheduled_plan_id' => null,
            'scheduled_price_cents' => null,
            'scheduled_billing_cycle' => null,
            'scheduled_change_at' => null,
        ]);

        $this->voidCycleChargesFrom($current, $from);
        $this->fireUpdated($current);

        return $current->fresh();
    }

    /**
     * Move a subscription onto its scheduled plan once the period it was
     * waiting for has ended. Returns whether anything changed.
     */
    public function applyScheduledChange(Subscription $subscription): bool
    {
        if (! $subscription->hasScheduledChange() || $subscription->scheduled_change_at->isFuture()) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::Cancelled) {
            $subscription->update([
                'scheduled_plan_id' => null,
                'scheduled_price_cents' => null,
                'scheduled_billing_cycle' => null,
                'scheduled_change_at' => null,
            ]);

            return false;
        }

        $plan = $subscription->scheduledPlan;
        $from = $subscription->plan_id;

        $subscription->update([
            'plan_id' => $subscription->scheduled_plan_id,
            'price_cents' => $subscription->scheduled_price_cents,
            'billing_cycle' => $subscription->scheduled_billing_cycle ?? $subscription->billing_cycle,
            // Snapshotted now, like at subscribe time: what the plan grants on
            // the day the workspace moves onto it.
            'quotas_snapshot' => $plan?->quotas ?? $subscription->quotas_snapshot,
            'features_snapshot' => $plan?->features ?? $subscription->features_snapshot,
            'scheduled_plan_id' => null,
            'scheduled_price_cents' => null,
            'scheduled_billing_cycle' => null,
            'scheduled_change_at' => null,
        ]);

        $this->gate->forget($subscription->tenant);
        $this->fireUpdated($subscription);

        Log::info('Scheduled plan change applied', [
            'subscription_id' => $subscription->id,
            'from_plan_id' => $from,
            'to_plan_id' => $subscription->plan_id,
        ]);

        return true;
    }

    /** Every downgrade whose period has ended. Called by billing:process-overdue. */
    public function applyDueScheduledChanges(): int
    {
        $applied = 0;

        Subscription::query()
            ->whereNotNull('scheduled_plan_id')
            ->whereNotNull('scheduled_change_at')
            ->where('scheduled_change_at', '<=', now())
            ->each(function (Subscription $subscription) use (&$applied) {
                try {
                    $applied += $this->applyScheduledChange($subscription) ? 1 : 0;
                } catch (\Throwable $e) {
                    Log::error('Could not apply a scheduled plan change', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $applied;
    }

    /**
     * Tell a gateway that charges a fixed amount on its own schedule what the
     * next cycles cost. Throws: a downgrade the gateway did not hear about is
     * one charged at the old price.
     */
    protected function syncRecurringAmount(Subscription $subscription, int $amountCents): void
    {
        $instrumentId = $subscription->payment_instrument_id;

        if (! $instrumentId) {
            return;
        }

        $gateway = $this->gateways->forSubscription($subscription);

        if (! $gateway instanceof HoldsRecurringAuthorisations || ! $gateway->renewsItself($instrumentId)) {
            return;
        }

        $gateway->updateRecurringAmount($instrumentId, $amountCents, $subscription->currency ?: 'BRL');
    }

    /** Close per-cycle charges already issued for the periods starting at `$from`. */
    protected function voidCycleChargesFrom(Subscription $subscription, ?CarbonInterface $from): void
    {
        if ($from === null) {
            return;
        }

        $subscription->invoices()
            ->where('status', InvoiceStatus::Pending->value)
            ->whereIn('payment_method', [PaymentMethod::Pix->value, PaymentMethod::Checkout->value])
            ->where('period_start', '>=', $from)
            ->get()
            ->each(fn (Invoice $invoice) => $this->cancelInvoice($invoice));
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
            // The workspace's own country, not the platform's. Sent as 'BR' for
            // everyone, an Indonesian customer's NPWP was declared a Brazilian
            // document on every charge.
            'document_country' => $tenant->market_code ?: 'BR',
            'consented_to_stored_instruments' => true,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Refuse before calling out, where the sentence can still say what to do.
     *
     * The gateway rejects a charge with no CPF or CNPJ by naming
     * `customer.document_number` — a field the person reading it has never
     * seen and cannot find in this product.
     */
    protected function assertBillable(?Tenant $tenant): void
    {
        if (! $tenant?->hasBillingIdentity()) {
            throw new MissingBillingIdentityException(MarketDocuments::codes($tenant?->market_code));
        }
    }

    /**
     * Copy what the customer needs to pay onto the invoice: a Pix QR into the
     * columns the SPA already reads, or the hosted checkout link.
     */
    protected function applyInstructions(Invoice $invoice, array $payment, CarbonInterface $fallbackExpiry): void
    {
        $instructions = $payment['instructions'] ?? [];
        $expiresAt = isset($instructions['expires_at'])
            ? Carbon::parse($instructions['expires_at'])
            : $fallbackExpiry;

        $invoice->update(array_merge(
            ['payment_id' => isset($payment['id']) ? (string) $payment['id'] : null],
            $invoice->payment_method === PaymentMethod::Checkout
                ? [
                    'checkout_url' => $instructions['redirect_url'] ?? null,
                    'checkout_expires_at' => $expiresAt,
                ]
                : [
                    'pix_qr_code' => $instructions['qr_code'] ?? null,
                    'pix_qr_code_base64' => $instructions['qr_code_image'] ?? null,
                    'pix_copy_paste' => $instructions['qr_code'] ?? null,
                    'pix_expires_at' => $expiresAt,
                ],
        ));
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

        $this->markInvoiceFailed($invoice, 'card_renewal', payment: $payment);

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

    protected function createPendingSubscription(
        Tenant $tenant,
        Plan $plan,
        PaymentMethod $method,
        ?string $gateway = null,
        ?array $quote = null,
        bool $deferSwitch = false,
    ): Subscription {
        $upgrade = ($quote['type'] ?? null) === PlanChange::UPGRADE;

        // A card pays (or is refused) in this same request, so the plan it
        // replaces keeps being the workspace's plan until the money is in —
        // the upgrade mechanism, used for every card purchase. Without it a
        // refused card cancelled a working plan (a comp, a lapsed-but-paid
        // period) and left the workspace locked behind a past_due row.
        $replaces = $upgrade
            ? ($quote['current']['subscription_id'] ?? null)
            : ($deferSwitch ? $tenant->current_subscription_id : null);
        $deferred = $upgrade || $replaces !== null;

        // A change started earlier and never paid is abandoned by starting
        // another: two pending upgrades would both take over once paid.
        $this->discardPendingChanges($tenant);

        // Before the tenant moves on: close whatever charge the old
        // subscription still has open (talks to the payment service, so keep it
        // out of the transaction).
        //
        // ⚠️ Not for an upgrade. The plan it replaces keeps working — and keeps
        // its charges — until the new one is paid; see completePlanSwitch().
        if (! $deferred) {
            $this->voidSupersededCharges($tenant);
        }

        // The country's own price, snapshotted with its currency. Resolved once,
        // here, because everything downstream reads the subscription.
        $price = $plan->priceForMarket($tenant->market_code);

        return DB::transaction(function () use ($tenant, $plan, $method, $price, $gateway, $upgrade, $quote, $replaces, $deferred) {
            if (! $deferred) {
                $this->supersedeCurrent($tenant);
            }

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'replaces_subscription_id' => $replaces,
                'proration_balance_cents' => $upgrade && ($quote['balance_credit_cents'] ?? 0) > 0
                    ? (int) $quote['balance_credit_cents']
                    : null,
                // Must NOT be a usable status. This row is committed and pointed at
                // by tenant.current_subscription_id before the provider is called,
                // so anything usable here grants free access when that call throws —
                // and with no current_period_end it would never lapse either.
                // Callers move it to Active once payment is confirmed.
                'status' => SubscriptionStatus::PastDue,
                'payment_method' => $method,
                'gateway' => $gateway,
                'billing_cycle' => $plan->billing_cycle->value,
                'price_cents' => $price?->amount_cents ?? $plan->price_cents,
                // Snapshotted with the price, not read off the plan later: a
                // frozen number whose unit can still move is not frozen.
                'currency' => $price?->currency ?: ($plan->currency ?: $tenant->currency()),
                'quotas_snapshot' => $plan->quotas,
                'features_snapshot' => $plan->features,
                'current_period_start' => now(),
                // A trial is for somebody arriving, not somebody already paying.
                'trial_ends_at' => ! $upgrade && $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
            ]);

            // An upgrade (or a card purchase) is not pointed at until it is
            // paid: the plan it replaces stays the workspace's plan meanwhile.
            if (! $deferred) {
                $this->setCurrent($tenant, $subscription);
            }

            return $subscription;
        });
    }

    /**
     * The first charge of a new subscription: the plan's price, minus the
     * unused part of the plan an upgrade replaces.
     *
     * @return array{amount_cents: int, proration_credit_cents: int|null}
     */
    protected function firstCharge(Subscription $subscription, ?array $quote): array
    {
        if (($quote['type'] ?? null) !== PlanChange::UPGRADE) {
            return ['amount_cents' => (int) $subscription->price_cents, 'proration_credit_cents' => null];
        }

        $discount = (int) ($quote['discount_cents'] ?? 0);

        return [
            'amount_cents' => max(0, (int) $subscription->price_cents - $discount),
            'proration_credit_cents' => $discount > 0 ? $discount : null,
        ];
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

        // An upgrade's first payment is what makes it the workspace's plan.
        $this->completePlanSwitch($subscription);

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

            // ⚠️ A preapproval on the plan being replaced would go on charging
            // the old price next to the new plan.
            $this->setRecurringState($current, 'cancelled');
        }
    }

    /**
     * Mark an invoice failed and keep *why* on the row itself.
     *
     * Production keeps no application log across deploys (storage/logs lives
     * inside the container), and a refused card is exactly the kind of failure
     * someone asks about days later. So the gateway's own answer — endpoint,
     * status, body, decline code — is written to `meta.failure`, where the Back
     * Office shows it. Operators only: tenants never read this field.
     *
     * @param  array<string, mixed>|null  $payment  A normalized payment that came back declined.
     */
    protected function markInvoiceFailed(Invoice $invoice, string $stage, ?\Throwable $e = null, ?array $payment = null): void
    {
        $invoice->update([
            'status' => InvoiceStatus::Failed,
            'meta' => array_merge($invoice->meta ?? [], ['failure' => $this->failureRecord($invoice, $stage, $e, $payment)]),
        ]);
    }

    /**
     * What markInvoiceFailed() keeps — also used for a refused renewal
     * authorisation, which is recorded without failing the paid invoice.
     *
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    protected function failureRecord(Invoice $invoice, string $stage, ?\Throwable $e = null, ?array $payment = null, ?string $message = null): array
    {
        $failure = array_filter([
            'at' => now()->toIso8601String(),
            'stage' => $stage,
            'gateway' => $invoice->gateway ?? 'payment_service',
            'message' => $message,
        ]);

        if ($e instanceof UpstreamServiceException) {
            $failure = array_merge($failure, array_filter([
                'ref' => $e->reference ?: null,
                'code' => $e->errorCode,
                'message' => $e->rawMessage,
                'response' => $e->details ?: null,
            ]));
        } elseif ($e) {
            $failure['message'] = class_basename($e).': '.Str::limit($e->getMessage(), 1000);
        }

        if ($payment) {
            $failure += array_filter([
                'payment_id' => $this->paymentIdOf($payment),
                'payment_status' => $payment['status'] ?? null,
                'decline' => $payment['decline'] ?? null,
            ]);
        }

        return $failure;
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
    protected function matchInvoice(array $payment, ?string $gateway = null): ?Invoice
    {
        $paymentId = $this->paymentIdOf($payment);

        if ($paymentId) {
            $byId = Invoice::where('payment_id', $paymentId)
                ->when($gateway !== null, fn ($q) => $gateway === BillingGateways::PAYMENT_SERVICE
                    ? $q->where(fn ($w) => $w->whereNull('gateway')->orWhere('gateway', BillingGateways::PAYMENT_SERVICE))
                    : $q->where('gateway', $gateway))
                ->first();

            if ($byId) {
                return $byId;
            }
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
