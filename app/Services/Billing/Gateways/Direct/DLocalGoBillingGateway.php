<?php

namespace App\Services\Billing\Gateways\Direct;

use App\Models\Tenant;
use App\Services\Billing\Gateways\BillingGateway;
use App\Services\Billing\Gateways\BillingGateways;
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
 *  - **The payer leaves.** `POST /v1/payments` answers with a `redirect_url`
 *    to a dLocal-hosted page, and that is the only way a payment completes —
 *    no Pix string, no card token we could keep. So the method is `checkout`
 *    and it is paid per cycle, exactly like Pix: nothing here renews itself.
 *  - **Nobody signs outbound.** A bearer token carrying both keys,
 *    `Bearer <api key>:<secret key>`.
 *  - **The notification is a pointer.** `{"payment_id": "…"}`, signed as
 *    HMAC-SHA256 over `api key + raw body` — so the status is always read back
 *    from the API, never from the body.
 *
 * ⚠️ dLocal Go is dLocal's Latin American self-service product. A market it
 * does not cover (Indonesia, per dLocal's own coverage list) is refused by the
 * API at charge time — the error reaches the customer as "cannot charge in
 * your currency yet", not as their fault.
 */
class DLocalGoBillingGateway implements BillingGateway
{
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

        return [[
            'method' => 'checkout',
            'instruction_type' => 'redirect',
            'currencies' => [strtoupper((string) $currency)],
            'max_installments' => 1,
            'public_key' => null,
            'merchant_initiated_cards' => false,
        ]];
    }

    public function cardSession(Tenant $tenant): array
    {
        // The card is typed on dLocal's page, never tokenised in ours.
        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            'dLocal Go takes cards on its hosted checkout only; there is no card session.',
            upstreamCode: 'unsupported_instrument',
            status: 422,
        );
    }

    public function createPayment(array $payload, string $idempotencyKey): array
    {
        if (($payload['payment_method'] ?? null) !== 'checkout') {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                "dLocal Go direct billing only takes hosted checkouts, not '".($payload['payment_method'] ?? '')."'.",
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

    /** `POST /v1/me` — dLocal Go's own credential check. A POST that reads. */
    public function verifyCredentials(): array
    {
        return $this->decode($this->http()->post('/v1/me', []));
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
        );
    }
}
