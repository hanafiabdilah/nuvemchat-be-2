<?php

namespace App\Services\Billing\Gateways\Direct;

use App\Models\Tenant;
use App\Services\Billing\Gateways\BillingGateway;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\OpensCardCheckouts;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Every market except Brazil, when PAYMENT_METHOD=direct: dLocal Go.
 *
 * Three facts about dLocal Go shape this file (the payment service's own
 * adapter documents the same ones):
 *
 *  - **Two ways to pay, split by whether it is a card.** Without a card the
 *    payer leaves: `POST /v1/payments` answers with a `redirect_url` to a
 *    dLocal-hosted page (method `checkout`, paid per cycle like Pix). A card
 *    stays on our page through SmartFields (`allow_transparent`) — only when
 *    the SmartFields key is set — and is opened with `allow_recurring`, so its
 *    `merchant_checkout_token` is what every later cycle is charged against
 *    (`POST /v1/payments/recurring/{token}`). The clock is ours, like a stored
 *    card at the payment service: `billing:charge-renewals` runs it.
 *  - **Nobody signs outbound.** A bearer token carrying both keys,
 *    `Bearer <api key>:<secret key>`.
 *  - **The notification is a pointer.** `{"payment_id": "…"}`, signed as
 *    HMAC-SHA256 over `api key + raw body` — so the status is always read back
 *    from the API, never from the body.
 *
 * Coverage (dLocal Go help center, Sep 2026): Latin America plus Indonesia,
 * Malaysia, Kenya and Nigeria — Indonesia in IDR with card, Alfamart, bank
 * transfer and OVO. A market outside that list is refused by the API at
 * charge time — the error reaches the customer as "cannot charge in your
 * currency yet", not as their fault.
 */
class DLocalGoBillingGateway implements BillingGateway, OpensCardCheckouts
{
    /**
     * A stored card is the checkout token of the first payment, prefixed so it
     * can never be mistaken for another gateway's instrument id.
     */
    public const INSTRUMENT_PREFIX = 'dlgo_rec:';

    public const SDK = 'dlocalgo';

    public function name(): string
    {
        return BillingGateways::DLOCALGO;
    }

    public function isConfigured(): bool
    {
        return DirectBillingConfig::dlocalGoApiKey() !== null
            && DirectBillingConfig::dlocalGoSecretKey() !== null;
    }

    public function paymentMethods(?string $currency): array
    {
        if (! $this->isConfigured() || blank($currency)) {
            return [];
        }

        $methods = [];

        // A card only with SmartFields: without that key the card could only be
        // typed on dLocal's page, and a card taken there is one charge, not a
        // subscription — so it stays inside `checkout` and is never sold as
        // automatic renewal.
        if ($this->cardFormAvailable()) {
            $methods[] = [
                'method' => 'card',
                'instruction_type' => 'card_form',
                'currencies' => [strtoupper((string) $currency)],
                'max_installments' => 1,
                'public_key' => DirectBillingConfig::dlocalGoSmartFieldsKey(),
                'merchant_initiated_cards' => true,
            ];
        }

        // ⚠️ The hosted page is deliberately not narrowed with `payment_type`:
        // its vocabulary (CREDIT_CARD, DEBIT_CARD, BANK_TRANSFER, VOUCHER) has
        // no word for a wallet, so excluding cards there risks hiding OVO in
        // Indonesia — the method that page exists to offer.
        $methods[] = [
            'method' => 'checkout',
            'instruction_type' => 'redirect',
            'currencies' => [strtoupper((string) $currency)],
            'max_installments' => 1,
            'public_key' => null,
            'merchant_initiated_cards' => false,
        ];

        return $methods;
    }

    public function cardFormAvailable(): bool
    {
        return $this->isConfigured() && DirectBillingConfig::dlocalGoSmartFieldsKey() !== null;
    }

    /**
     * What the browser needs to draw the card field. Unlike a tokenise-first
     * SDK this is not enough on its own: SmartFields is initialised with the
     * checkout token of a payment, which `openCardCheckout()` creates —
     * `requires_checkout` tells the page to ask for one first.
     */
    public function cardSession(Tenant $tenant): array
    {
        if (! $this->cardFormAvailable()) {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'dLocal Go has no SmartFields key configured; cards are only taken on the hosted checkout.',
                upstreamCode: 'unsupported_instrument',
                status: 422,
            );
        }

        return [
            'sdk' => self::SDK,
            'sdk_url' => DirectBillingConfig::dlocalGoSmartFieldsSdkUrl(),
            'public_key' => DirectBillingConfig::dlocalGoSmartFieldsKey(),
            'provider' => BillingGateways::DLOCALGO,
            'requires_checkout' => true,
        ];
    }

    public function openCardCheckout(array $payload): array
    {
        $customer = $payload['customer'] ?? [];
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        $returnUrl = $payload['return_url'] ?? DirectBillingConfig::returnUrl('/billing');

        $response = $this->http()->post('/v1/payments', array_filter([
            'amount' => $this->decimal((int) $payload['amount'], $currency),
            'currency' => $currency,
            'country' => strtoupper((string) ($customer['document_country'] ?? '')) ?: null,
            'order_id' => $payload['order_reference'] ?? null,
            'description' => $payload['description'] ?? null,
            'notification_url' => $this->notificationUrl(),
            // Where 3-D Secure lands the customer when the issuer asks for it.
            'success_url' => $returnUrl,
            'back_url' => $returnUrl,
            'payer' => $this->payer($customer),
            // The form is ours…
            'allow_transparent' => true,
            // …and the card it takes is kept for the cycles after this one.
            // dLocal Go narrows the payment to cards when this is set, which is
            // exactly what this path is.
            'allow_recurring' => true,
            ...$this->expiry($payload['expires_at'] ?? null),
        ], fn ($value) => $value !== null && $value !== []));

        $data = $this->decode($response);
        $token = (string) ($data['merchant_checkout_token'] ?? '');

        if ($token === '') {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'dLocal Go created a payment without a merchant_checkout_token.',
                upstreamCode: 'payment_refused',
                status: 502,
            );
        }

        return [
            'payment' => $this->normalizePayment($data, $currency),
            'checkout_token' => $token,
        ];
    }

    public function confirmCardCheckout(string $checkoutToken, string $cardToken, array $customer): array
    {
        [$first, $last] = $this->splitName((string) ($customer['name'] ?? ''));

        $response = $this->http()->post('/v1/payments/confirm/'.rawurlencode($checkoutToken), array_filter([
            'cardToken' => $cardToken,
            'clientFirstName' => $first,
            'clientLastName' => $last,
            'clientDocumentType' => $customer['document_type'] ?? null,
            'clientDocument' => $customer['document_number'] ?? null,
            'clientEmail' => $customer['email'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $data = $this->decode($response);
        $payment = $this->normalizePayment($data, null);

        // A 3-D Secure challenge can come back as nothing but a redirect: the
        // payment is waiting on the customer, not refused.
        if ($payment['status'] === 'unknown' && filled($data['redirect_url'] ?? null) && blank($data['status'] ?? null)) {
            $payment['status'] = 'pending';
        }

        if (in_array($payment['status'], ['paid', 'pending'], true)) {
            $payment['instrument'] = ['id' => self::INSTRUMENT_PREFIX.$checkoutToken];
        }

        return $payment;
    }

    public function createPayment(array $payload, string $idempotencyKey): array
    {
        $instrument = (string) ($payload['instrument_id'] ?? '');

        if (($payload['payment_method'] ?? null) === 'card' && str_starts_with($instrument, self::INSTRUMENT_PREFIX)) {
            return ['data' => $this->chargeStoredCard($instrument, $payload)];
        }

        if (($payload['payment_method'] ?? null) !== 'checkout') {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                "dLocal Go direct billing takes a hosted checkout or a stored card here, not '".($payload['payment_method'] ?? '')."' (a first card goes through openCardCheckout).",
                upstreamCode: 'unsupported_instrument',
                status: 422,
            );
        }

        $customer = $payload['customer'] ?? [];
        $currency = strtoupper((string) ($payload['currency'] ?? ''));
        $returnUrl = $payload['return_url'] ?? DirectBillingConfig::returnUrl('/billing');

        $response = $this->http()->post('/v1/payments', array_filter([
            'amount' => $this->decimal((int) $payload['amount'], $currency),
            'currency' => $currency,
            // The workspace's market, which is also the country its payer is in.
            'country' => strtoupper((string) ($customer['document_country'] ?? '')) ?: null,
            // Only [A-Za-z0-9-_] — which BillingService's references already are.
            'order_id' => $payload['order_reference'] ?? null,
            'description' => $payload['description'] ?? null,
            'notification_url' => $this->notificationUrl(),
            'success_url' => $returnUrl,
            'back_url' => $returnUrl,
            'payer' => $this->payer($customer),
            ...$this->expiry($payload['expires_at'] ?? null),
        ], fn ($value) => $value !== null && $value !== []));

        return ['data' => $this->normalizePayment($this->decode($response), $currency)];
    }

    /**
     * A later cycle on the card kept by the first SmartFields payment. Nobody
     * is at a screen: no card form, no 3-D Secure.
     *
     * `orderId` is the cycle's reference, so a retried call collapses into the
     * same payment on their side (error 5009, duplicated) — the local
     * already-billed guard in BillingService is the first line.
     */
    protected function chargeStoredCard(string $instrumentId, array $payload): array
    {
        $token = substr($instrumentId, strlen(self::INSTRUMENT_PREFIX));
        $currency = strtoupper((string) ($payload['currency'] ?? ''));

        $response = $this->http()->post('/v1/payments/recurring/'.rawurlencode($token), array_filter([
            'amount' => $this->decimal((int) $payload['amount'], $currency),
            'description' => $payload['description'] ?? null,
            'orderId' => $payload['order_reference'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $payment = $this->normalizePayment($this->decode($response), $currency);

        // A refused renewal comes back with a link for the customer to fix the
        // card on dLocal's page. It is not an instruction for this charge, and
        // it must not turn a decline into something that looks payable.
        if ($payment['status'] !== 'pending') {
            $payment['instructions'] = [];
        }

        return $payment;
    }

    public function getPayment(string $id): array
    {
        $response = $this->http()->get("/v1/payments/{$id}");

        // A payment we cannot find is not one that was refused.
        if ($response->status() === 404) {
            return ['id' => $id, 'status' => 'unknown'];
        }

        return $this->normalizePayment($this->decode($response), null);
    }

    public function renewsItself(string $instrumentId): bool
    {
        return false;
    }

    public function setRecurringState(string $instrumentId, string $state): void {}

    /**
     * `GET /v1/me` — the account behind the keys; creates nothing.
     *
     * ⚠️ GET, not POST. A POST (which the payment service's adapter notes
     * claim is required) is answered `500 {"code":7000,"message":
     * "internal_server_error"}` for any keys at all — verified against
     * production, where the same keys answer GET with the merchant record.
     */
    public function verifyCredentials(): array
    {
        return $this->decode($this->http()->get('/v1/me'));
    }

    /**
     * `Authorization: V2-HMAC-SHA256, Signature: <hex>` where the hex is
     * HMAC-SHA256 over `api key + raw body`, keyed by the secret key.
     *
     * No timestamp is signed, so there is no replay window to enforce — which
     * is harmless here: the body carries no status, and a replay only makes us
     * read the payment again.
     */
    public function verifyWebhook(Request $request): bool
    {
        $apiKey = DirectBillingConfig::dlocalGoApiKey();
        $secret = DirectBillingConfig::dlocalGoSecretKey();

        if ($apiKey === null || $secret === null) {
            return false;
        }

        if (! preg_match('/Signature:\s*([0-9a-f]+)/i', (string) $request->header('Authorization', ''), $matches)) {
            return false;
        }

        $expected = hash_hmac('sha256', $apiKey.$request->getContent(), $secret);

        return hash_equals($expected, strtolower($matches[1]));
    }

    // --- Reading ------------------------------------------------------------

    /**
     * `CANCELLED` is a checkout that ended without payment — expired, not a
     * decline: no issuer refused anything.
     */
    public function normalizePayment(array $payment, ?string $currency): array
    {
        $status = strtoupper((string) ($payment['status'] ?? ''));

        return [
            'id' => isset($payment['id']) ? (string) $payment['id'] : null,
            'order_reference' => $payment['order_id'] ?? null,
            'status' => match ($status) {
                'PAID' => 'paid',
                'PENDING' => 'pending',
                'REJECTED' => 'declined',
                'EXPIRED', 'CANCELLED' => 'expired',
                default => 'unknown',
            },
            'instructions' => filled($payment['redirect_url'] ?? null) ? [
                'type' => 'redirect',
                'redirect_url' => $payment['redirect_url'],
                'expires_at' => $payment['expiration_date'] ?? null,
            ] : [],
            'decline' => $status === 'REJECTED'
                ? ['category' => 'unknown', 'code' => $payment['rejected_reason'] ?? null]
                : null,
        ];
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * SmartFields' confirm wants the name in two halves; the billing profile
     * holds one. A single word is both — an empty last name is refused.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        if ($parts === []) {
            return [null, null];
        }

        $first = array_shift($parts);

        return [$first, $parts === [] ? $first : implode(' ', $parts)];
    }

    protected function payer(array $customer): array
    {
        return array_filter([
            'name' => $customer['name'] ?? null,
            'email' => $customer['email'] ?? null,
            'document_type' => $customer['document_type'] ?? null,
            'document' => $customer['document_number'] ?? null,
            // A stable per-customer id for their fraud model, without sending
            // the address itself twice.
            'user_reference' => isset($customer['reference']) ? (string) $customer['reference'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** dLocal Go takes a lifetime in minutes rather than a deadline. */
    protected function expiry(?string $expiresAt): array
    {
        if (blank($expiresAt)) {
            return [];
        }

        return [
            'expiration_type' => 'MINUTES',
            'expiration_value' => max(1, (int) ceil(now()->diffInMinutes(Carbon::parse($expiresAt), absolute: false))),
        ];
    }

    protected function notificationUrl(): ?string
    {
        $url = DirectBillingConfig::dlocalGoWebhookUrl();

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Minor units (always hundredths here, whatever the currency) to the
     * decimal dLocal Go expects, with as many places as the currency has —
     * a rupiah amount carries none.
     */
    protected function decimal(int $cents, string $currency): float|int
    {
        $decimals = Money::decimals($currency);
        $value = round($cents / 100, $decimals);

        return $decimals === 0 ? (int) $value : $value;
    }

    protected function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'dLocal Go direct billing has no API key / secret key configured.',
                upstreamCode: 'payment_service_unconfigured',
                status: 503,
            );
        }

        return Http::baseUrl(DirectBillingConfig::dlocalGoBaseUrl())
            ->withHeaders([
                // One header, both keys, colon-separated — not two headers and
                // not basic auth; either of those is a 403 that reads like a
                // wrong key.
                'Authorization' => sprintf(
                    'Bearer %s:%s',
                    DirectBillingConfig::dlocalGoApiKey(),
                    DirectBillingConfig::dlocalGoSecretKey(),
                ),
            ])
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(45)
            // Reads only would be safer, but a dropped connection on create is
            // collapsed by `order_id` on their side (error 5009, duplicated).
            ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** @return array<string, mixed> */
    protected function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $body = $response->json();
        $message = is_array($body)
            ? trim(($body['param'] ?? '') !== '' ? "{$body['param']}: ".($body['message'] ?? '') : (string) ($body['message'] ?? ''))
            : $response->body();
        $needle = Str::lower($message);

        $code = match (true) {
            in_array($response->status(), [401, 403], true) => 'payment_service_unconfigured',
            str_contains($needle, 'country'),
            str_contains($needle, 'currency') => 'currency_not_supported',
            $response->status() >= 400 && $response->status() < 500 => 'payment_refused',
            default => null,
        };

        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            'dLocal Go (direct billing): '.($message ?: $response->body()),
            upstreamCode: $code,
            status: $response->status(),
            context: ['gateway' => 'dlocalgo', 'gateway_status' => $response->status()],
            details: UpstreamError::httpDetails($response),
        );
    }
}
