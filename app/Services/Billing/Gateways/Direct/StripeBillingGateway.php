<?php

namespace App\Services\Billing\Gateways\Direct;

use App\Models\Tenant;
use App\Services\Billing\Gateways\BillingGateway;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\KeepsRenewalCards;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Brazil's card subscriptions, when PAYMENT_METHOD=direct: Pingly's own Stripe
 * account.
 *
 * One job, and deliberately only one: a card that renews with nobody at a
 * screen. Mercado Pago can only do that through a preapproval — a schedule it
 * owns — and Stripe lets us keep the card and charge it ourselves, so the
 * renewal clock stays in `billing:charge-renewals` like every other stored
 * card in this product. Pix and top-ups never come here.
 *
 *  - **First cycle**: the browser turns the card into a PaymentMethod
 *    (`pm_…`, Stripe.js), and one PaymentIntent charges it with
 *    `setup_future_usage=off_session`, which is what keeps it for later.
 *  - **Every cycle after**: a PaymentIntent with `off_session=true` on that
 *    same PaymentMethod. This gateway never `renewsItself()`.
 *  - **No Stripe Subscriptions, no Stripe Invoices.** Proration, scheduled
 *    downgrades and the period itself are PlanChange's and BillingService's;
 *    a second clock at Stripe would be a second opinion about all three.
 *
 * A stored card is `stripe_pm:{customer}:{payment method}` — both halves,
 * because an off-session charge has to name the customer the card is attached
 * to and the renewal has nothing else to read it from.
 *
 * ⚠️ A refused card is an *answer*, not an error. Stripe says it with HTTP 402,
 * and this gateway turns that into a normalized `declined` payment instead of
 * throwing: a renewal that throws is logged and forgotten, while one that comes
 * back declined drops a dead card and tells the customer.
 *
 * Errors are translated under UpstreamProvider::PaymentService, like the other
 * direct gateways: the account is ours, so "check your Stripe key" is never
 * something to say to a customer.
 */
class StripeBillingGateway implements BillingGateway, KeepsRenewalCards
{
    /** How a kept card is stored in `subscriptions.payment_instrument_id`. */
    public const INSTRUMENT_PREFIX = 'stripe_pm:';

    public const SDK = 'stripe';

    /** How far a webhook's timestamp may be from now before it is a replay. */
    protected const WEBHOOK_TOLERANCE_SECONDS = 300;

    /**
     * Refusals that will be the same refusal tomorrow. Retrying them only
     * raises the failure ratio the card networks judge the account by.
     */
    protected const PERMANENT_DECLINES = [
        'lost_card', 'stolen_card', 'pickup_card', 'restricted_card', 'invalid_account',
        'new_account_information_available', 'card_not_supported', 'expired_card',
        'incorrect_number', 'invalid_number', 'invalid_expiry_month', 'invalid_expiry_year',
        'merchant_blacklist', 'revocation_of_authorization', 'revocation_of_all_authorizations',
        'stop_payment_order',
    ];

    public function name(): string
    {
        return BillingGateways::STRIPE;
    }

    /**
     * Both keys, not just the secret one: without the publishable key the
     * browser cannot tokenise, and routing card subscriptions to a gateway
     * that cannot draw a card form would take the card tile away entirely.
     */
    public function isConfigured(): bool
    {
        return DirectBillingConfig::stripeSecretKey() !== null
            && DirectBillingConfig::stripePublishableKey() !== null;
    }

    public function paymentMethods(?string $currency): array
    {
        if (! $this->isConfigured() || blank($currency)) {
            return [];
        }

        return [[
            'method' => 'card',
            'instruction_type' => 'card',
            'currencies' => [strtoupper((string) $currency)],
            'max_installments' => 1,
            'public_key' => DirectBillingConfig::stripePublishableKey(),
            'merchant_initiated_cards' => true,
        ]];
    }

    public function cardSession(Tenant $tenant): array
    {
        return [
            'sdk' => self::SDK,
            'public_key' => DirectBillingConfig::stripePublishableKey(),
            'provider' => $this->name(),
        ];
    }

    public function createPayment(array $payload, string $idempotencyKey): array
    {
        $instrument = (string) ($payload['instrument_id'] ?? '');

        if (($payload['payment_method'] ?? null) === 'card' && str_starts_with($instrument, self::INSTRUMENT_PREFIX)) {
            return ['data' => $this->chargeStoredCard($instrument, $payload, $idempotencyKey)];
        }

        if (($payload['payment_method'] ?? null) === 'card' && filled($payload['card_token'] ?? null)) {
            return ['data' => $this->chargeNewCard($payload, $idempotencyKey)];
        }

        // Pix, a hosted checkout, a top-up: none of them is routed here.
        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            "Stripe direct billing only takes card subscriptions, not '".($payload['payment_method'] ?? '')."'.",
            upstreamCode: 'unsupported_instrument',
            status: 422,
        );
    }

    public function getPayment(string $id): array
    {
        $response = $this->http()->get('/v1/payment_intents/'.rawurlencode($id), ['expand' => ['latest_charge']]);

        // A payment we cannot find is not one that was refused.
        if ($response->status() === 404) {
            return ['id' => $id, 'status' => 'unknown'];
        }

        return $this->normalizePayment($this->decode($response));
    }

    public function renewsItself(string $instrumentId): bool
    {
        return false;
    }

    /** Nothing stands at Stripe: we simply stop asking. */
    public function setRecurringState(string $instrumentId, string $state): void {}

    /** `GET /v1/account` — the account behind the key; creates nothing. */
    public function verifyCredentials(): array
    {
        return $this->decode($this->http()->get('/v1/account'));
    }

    /**
     * `Stripe-Signature: t=<unix>,v1=<hex>[,v1=<hex>]` — HMAC-SHA256 over
     * `{t}.{raw body}`, keyed by the endpoint's signing secret. Several `v1`
     * values appear while a secret is being rolled; any one matching is enough.
     *
     * Returns true (valid), false (present and wrong, or too old — refuse), or
     * null (no secret stored: not checkable). Null is still processed, because
     * the body is only ever used to know *which* payment to read back.
     */
    public function verifyWebhook(Request $request): ?bool
    {
        $secret = DirectBillingConfig::stripeWebhookSecret();

        if ($secret === null) {
            return null;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $request->header('Stripe-Signature', '')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if (! ctype_digit((string) $timestamp) || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    // --- Charges ------------------------------------------------------------

    /**
     * The first cycle: charge the card typed now, and keep it.
     *
     * Automatic payment methods with redirects turned off, for one reason: it
     * makes an issuer's 3-D Secure request come back as `requires_action`
     * handled by Stripe.js on our own page, with no redirect and so no return
     * URL to get wrong. The card is chosen by the `payment_method` we pass, so
     * nothing else enabled in the Dashboard is ever offered here.
     *
     * ⚠️ Not `payment_method_types=[card]`, which said the same thing until
     * Stripe stopped accepting it: accounts opened in 2026 answer that
     * parameter with a 400 ("payment methods are now managed from your
     * Dashboard settings"), so every charge failed before reaching the card.
     */
    protected function chargeNewCard(array $payload, string $idempotencyKey): array
    {
        $customerId = filled($payload['customer_id'] ?? null)
            ? (string) $payload['customer_id']
            : $this->createCustomer($payload['customer'] ?? [], $idempotencyKey);

        return $this->charge([
            'customer' => $customerId,
            'payment_method' => (string) $payload['card_token'],
            'setup_future_usage' => 'off_session',
        ], $payload, $idempotencyKey);
    }

    /**
     * A later cycle, with nobody at a screen. An issuer that insists on
     * authentication here cannot get it, so it comes back as a decline
     * (`authentication_required`) and the customer pays the cycle by hand.
     */
    protected function chargeStoredCard(string $instrumentId, array $payload, string $idempotencyKey): array
    {
        [$customerId, $paymentMethodId] = $this->instrumentParts($instrumentId);

        return $this->charge([
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session' => 'true',
        ], $payload, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $card  customer, payment_method and how it is being used
     * @return array<string, mixed> The normalized payment.
     */
    protected function charge(array $card, array $payload, string $idempotencyKey): array
    {
        $currency = strtoupper((string) ($payload['currency'] ?? 'BRL'));

        $response = $this->http($idempotencyKey)->post('/v1/payment_intents', array_filter([
            'amount' => $this->minorUnits((int) $payload['amount'], $currency),
            'currency' => strtolower($currency),
            // Form-encoded: a PHP `true` would be sent as "1", which Stripe refuses.
            'confirm' => 'true',
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'description' => $payload['description'] ?? null,
            'metadata' => array_filter([
                // What ties the PaymentIntent back to our invoice when a
                // notification overtakes the response that created it.
                'order_reference' => $payload['order_reference'] ?? null,
                'tenant_id' => $payload['metadata']['tenant_id'] ?? null,
                'subscription_id' => $payload['metadata']['subscription_id'] ?? null,
            ], fn ($value) => $value !== null),
            'expand' => ['latest_charge'],
            ...$card,
        ], fn ($value) => $value !== null && $value !== []));

        // The card was refused. The PaymentIntent exists and says why.
        if ($response->status() === 402) {
            return $this->declinedFrom($response, $payload);
        }

        $payment = $this->normalizePayment($this->decode($response));
        $payment['order_reference'] ??= $payload['order_reference'] ?? null;

        return $payment;
    }

    protected function createCustomer(array $customer, string $idempotencyKey): string
    {
        $response = $this->http('customer-'.$idempotencyKey)->post('/v1/customers', array_filter([
            'name' => $customer['name'] ?? null,
            'email' => $customer['email'] ?? null,
            'metadata' => array_filter([
                'reference' => isset($customer['reference']) ? (string) $customer['reference'] : null,
                'document' => $customer['document_number'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ], fn ($value) => $value !== null && $value !== []));

        $id = (string) ($this->decode($response)['id'] ?? '');

        if ($id === '') {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'Stripe created a customer without an id.',
                upstreamCode: 'payment_refused',
                status: 502,
            );
        }

        return $id;
    }

    // --- Reading ------------------------------------------------------------

    /**
     * A PaymentIntent in the shared vocabulary.
     *
     * A refund never changes the intent's own status — it stays `succeeded` —
     * so the charge underneath is what says the money went back. It is read
     * only when expanded; a bare id tells nothing and is left alone.
     */
    public function normalizePayment(array $intent): array
    {
        $status = (string) ($intent['status'] ?? '');
        $error = $intent['last_payment_error'] ?? null;
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $customerId = is_array($intent['customer'] ?? null) ? ($intent['customer']['id'] ?? null) : ($intent['customer'] ?? null);
        $methodId = is_array($intent['payment_method'] ?? null) ? ($intent['payment_method']['id'] ?? null) : ($intent['payment_method'] ?? null);
        $cardDetails = $charge['payment_method_details']['card'] ?? null;

        $normalized = match ($status) {
            'succeeded' => match (true) {
                (bool) ($charge['disputed'] ?? false) => 'disputed',
                (bool) ($charge['refunded'] ?? false) => 'refunded',
                (int) ($charge['amount_refunded'] ?? 0) > 0 => 'partially_refunded',
                default => 'paid',
            },
            'processing', 'requires_action', 'requires_confirmation' => 'pending',
            'requires_capture' => 'authorized',
            'canceled' => 'voided',
            // Back to "give me a card" after a failed attempt is a decline;
            // without one it is an intent nobody has tried to pay yet.
            'requires_payment_method' => $error ? 'declined' : 'created',
            default => 'unknown',
        };

        $kept = in_array($normalized, ['paid', 'pending'], true) && $customerId && $methodId;

        return [
            'id' => isset($intent['id']) ? (string) $intent['id'] : null,
            'order_reference' => $intent['metadata']['order_reference'] ?? null,
            'status' => $normalized,
            // The issuer wants the cardholder: Stripe.js finishes it in the
            // page with this secret, and the notification settles the invoice.
            'instructions' => $status === 'requires_action' && filled($intent['client_secret'] ?? null)
                ? ['type' => 'card_action', 'client_secret' => $intent['client_secret']]
                : [],
            'instrument' => $kept ? ['id' => self::INSTRUMENT_PREFIX.$customerId.':'.$methodId] : null,
            'customer' => $customerId ? ['id' => (string) $customerId] : null,
            'card' => is_array($cardDetails) ? array_filter([
                'brand' => $cardDetails['brand'] ?? null,
                'last_four' => $cardDetails['last4'] ?? null,
                'exp_month' => isset($cardDetails['exp_month']) ? (int) $cardDetails['exp_month'] : null,
                'exp_year' => isset($cardDetails['exp_year']) ? (int) $cardDetails['exp_year'] : null,
            ], fn ($value) => $value !== null) : null,
            'decline' => $normalized === 'declined' ? $this->declineOf($error ?? []) : null,
            'metadata' => $intent['metadata'] ?? [],
        ];
    }

    /**
     * HTTP 402: `error` carries the reason and, almost always, the intent it
     * happened on. Without the intent there is still a refusal to report.
     */
    protected function declinedFrom(Response $response, array $payload): array
    {
        $error = $response->json('error') ?? [];
        $intent = is_array($error['payment_intent'] ?? null) ? $error['payment_intent'] : [];

        $payment = $intent === []
            ? ['id' => null, 'instructions' => [], 'instrument' => null, 'customer' => null, 'card' => null, 'metadata' => []]
            : $this->normalizePayment($intent);

        return array_merge($payment, [
            'status' => 'declined',
            'order_reference' => $payment['order_reference'] ?? $payload['order_reference'] ?? null,
            'instructions' => [],
            'instrument' => null,
            'decline' => $this->declineOf($error),
        ]);
    }

    /**
     * `category` is what a renewal acts on (see RenewalRetry):
     *
     *  - `permanent` — the card is gone (lost, stolen, closed, expired) or the
     *    issuer said `do_not_try_again`. The card is dropped.
     *  - `action_required` — the issuer wants the cardholder: 3-D Secure
     *    (`authentication_required`), or `confirm_card_data`. Retrying with
     *    nobody at the screen gets the same answer, so the Billing page asks.
     *  - `temporary` — anything else, notably no funds today.
     *
     * `advice` is the issuer's own advice code when it sends one. It overrides
     * our guess because it is the bank telling us, and ignoring a
     * `do_not_try_again` is what the card networks fine merchants for.
     *
     * @return array{category: string, code: ?string, advice: ?string}
     */
    protected function declineOf(array $error): array
    {
        // `decline_code` is the issuer's reason; `code` is Stripe's own
        // (`expired_card`, `authentication_required`, or just `card_declined`).
        $code = $error['decline_code'] ?? $error['code'] ?? null;
        $advice = $error['advice_code'] ?? null;

        return [
            'category' => match (true) {
                $advice === 'do_not_try_again',
                in_array($code, self::PERMANENT_DECLINES, true),
                in_array($error['code'] ?? null, self::PERMANENT_DECLINES, true) => 'permanent',
                $advice === 'confirm_card_data',
                ($error['code'] ?? null) === 'authentication_required',
                $code === 'authentication_required' => 'action_required',
                $code === null => 'unknown',
                default => 'temporary',
            },
            'code' => $code,
            'advice' => $advice,
        ];
    }

    // --- Replacing the card behind a plan ------------------------------------

    /**
     * Keep a new card for later renewals without charging it: a SetupIntent
     * with `usage=off_session`, confirmed now.
     *
     * The issuer still gets a word in — a SetupIntent can be refused, or come
     * back asking for 3-D Secure — so this is checked the way a first charge
     * is, just without the money. A card that was never confirmed for
     * off-session use is the one that fails at the next renewal.
     */
    public function keepCard(array $payload, string $idempotencyKey): array
    {
        $customerId = filled($payload['customer_id'] ?? null)
            ? (string) $payload['customer_id']
            : $this->createCustomer($payload['customer'] ?? [], $idempotencyKey);

        $response = $this->http('setup-'.$idempotencyKey)->post('/v1/setup_intents', array_filter([
            'customer' => $customerId,
            'payment_method' => (string) ($payload['card_token'] ?? ''),
            'confirm' => 'true',
            'usage' => 'off_session',
            // Same reason as charge(): the card is the payment_method passed,
            // and redirects off keeps 3-D Secure on our own page.
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'metadata' => array_filter([
                'tenant_id' => $payload['metadata']['tenant_id'] ?? null,
                'subscription_id' => $payload['metadata']['subscription_id'] ?? null,
            ], fn ($value) => $value !== null),
            'expand' => ['payment_method'],
        ], fn ($value) => $value !== null && $value !== []));

        // Refused by the issuer: the SetupIntent exists and says why.
        if ($response->status() === 402) {
            $error = $response->json('error') ?? [];
            $intent = is_array($error['setup_intent'] ?? null) ? $error['setup_intent'] : [];

            return array_merge($this->normalizeSetup($intent + ['customer' => $customerId]), [
                'status' => 'declined',
                'client_secret' => null,
                'instrument' => null,
                'decline' => $this->declineOf($error),
            ]);
        }

        return $this->normalizeSetup($this->decode($response));
    }

    public function readKeptCard(string $id): array
    {
        $response = $this->http()->get('/v1/setup_intents/'.rawurlencode($id), ['expand' => ['payment_method']]);

        if ($response->status() === 404) {
            return ['id' => $id, 'status' => 'unknown', 'client_secret' => null, 'instrument' => null, 'customer' => null, 'card' => null, 'decline' => null];
        }

        return $this->normalizeSetup($this->decode($response));
    }

    public function cancelPayment(string $id): void
    {
        $this->http('cancel-'.$id)->post('/v1/payment_intents/'.rawurlencode($id).'/cancel');
    }

    /** A SetupIntent in KeepsRenewalCards' vocabulary. */
    public function normalizeSetup(array $intent): array
    {
        $status = (string) ($intent['status'] ?? '');
        $error = $intent['last_setup_error'] ?? null;
        $customerId = is_array($intent['customer'] ?? null) ? ($intent['customer']['id'] ?? null) : ($intent['customer'] ?? null);
        $method = $intent['payment_method'] ?? null;
        $methodId = is_array($method) ? ($method['id'] ?? null) : $method;
        $card = is_array($method) ? ($method['card'] ?? null) : null;

        $normalized = match ($status) {
            'succeeded' => 'ready',
            'requires_action', 'processing', 'requires_confirmation' => 'pending',
            'canceled' => 'declined',
            'requires_payment_method' => $error ? 'declined' : 'unknown',
            default => 'unknown',
        };

        return [
            'id' => isset($intent['id']) ? (string) $intent['id'] : null,
            'status' => $normalized,
            'client_secret' => $status === 'requires_action' ? ($intent['client_secret'] ?? null) : null,
            'instrument' => $normalized === 'ready' && $customerId && $methodId
                ? ['id' => self::INSTRUMENT_PREFIX.$customerId.':'.$methodId]
                : null,
            'customer' => $customerId ? ['id' => (string) $customerId] : null,
            'card' => is_array($card) ? array_filter([
                'brand' => $card['brand'] ?? null,
                'last_four' => $card['last4'] ?? null,
                'exp_month' => isset($card['exp_month']) ? (int) $card['exp_month'] : null,
                'exp_year' => isset($card['exp_year']) ? (int) $card['exp_year'] : null,
            ], fn ($value) => $value !== null) : null,
            'decline' => $normalized === 'declined' ? $this->declineOf($error ?? ['code' => $status === 'canceled' ? 'canceled' : null]) : null,
        ];
    }

    // --- Plumbing -----------------------------------------------------------

    /** @return array{0: string, 1: string} customer id, payment method id */
    protected function instrumentParts(string $instrumentId): array
    {
        $parts = explode(':', substr($instrumentId, strlen(self::INSTRUMENT_PREFIX)), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'A Stripe stored card is missing its customer or payment method id.',
                upstreamCode: 'instrument_not_chargeable',
                status: 422,
            );
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Our amounts are always hundredths, whatever the currency; Stripe wants
     * the currency's own smallest unit, which for one without decimals is the
     * whole number.
     */
    protected function minorUnits(int $cents, string $currency): int
    {
        return Money::decimals($currency) === 0 ? (int) round($cents / 100) : $cents;
    }

    protected function http(?string $idempotencyKey = null): PendingRequest
    {
        $key = DirectBillingConfig::stripeSecretKey();

        if ($key === null) {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'Stripe direct billing has no secret key configured.',
                upstreamCode: 'payment_service_unconfigured',
                status: 503,
            );
        }

        $request = Http::baseUrl(DirectBillingConfig::STRIPE_BASE_URL)
            ->withToken($key)
            ->asForm()
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(45)
            // Connection failures only. Every write carries Idempotency-Key,
            // which Stripe replays, so a retry returns the original.
            ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException, throw: false);

        return $idempotencyKey ? $request->withHeaders(['Idempotency-Key' => $idempotencyKey]) : $request;
    }

    /** @return array<string, mixed> */
    protected function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $response->json('error') ?? [];
        $message = trim(implode(' ', array_filter([
            $error['code'] ?? null,
            $error['param'] ?? null,
            $error['message'] ?? null,
        ]))) ?: $response->body();

        $code = match (true) {
            // Ours: a wrong, revoked or restricted key is never the customer's to fix.
            in_array($response->status(), [401, 403], true) => 'payment_service_unconfigured',
            ($error['type'] ?? null) === 'card_error' => 'payment_declined',
            // Stripe refusing the request we built, never the card: telling
            // the customer to "check the amount and the card" sends them after
            // something that was never the problem.
            ($error['type'] ?? null) === 'invalid_request_error' => 'payment_request_invalid',
            $response->status() >= 400 && $response->status() < 500 => 'payment_refused',
            default => null,
        };

        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            'Stripe (direct billing): '.$message,
            upstreamCode: $code,
            status: $response->status(),
            context: ['gateway' => 'stripe', 'gateway_status' => $response->status()],
            details: UpstreamError::httpDetails($response),
        );
    }
}
