<?php

namespace App\Services\Billing\PaymentService;

use Illuminate\Http\Request;

/**
 * Verifies an inbound payment-service webhook.
 *
 * `X-Payment-Signature: t=<unix>,v1=<hex>` where the hex is an HMAC-SHA256 over
 * `"{timestamp}.{raw body}"`.
 *
 * ⚠️ Against the **raw bytes**. Parsing the JSON and re-encoding it reorders
 * keys and breaks the hash for a message that was perfectly genuine.
 *
 * ⚠️ And an empty secret **refuses**, unlike the MercadoPago verifier this
 * replaces, which waved everything through when unconfigured. That leniency was
 * defensible for a route already carrying live traffic; it is indefensible for
 * a new one, where an unsigned body can activate a subscription nobody paid for.
 */
class WebhookSignatureVerifier
{
    public function __construct(
        protected ?string $secret = null,
        protected int $toleranceSeconds = PaymentServiceConfig::WEBHOOK_TOLERANCE_SECONDS,
    ) {
        $this->secret ??= PaymentServiceConfig::webhookSecret();
    }

    public function verify(Request $request): bool
    {
        if (empty($this->secret)) {
            return false;
        }

        [$timestamp, $presented] = $this->parse((string) $request->header('X-Payment-Signature'));

        if ($timestamp === null || $presented === null) {
            return false;
        }

        // The timestamp is inside the signed string, not merely beside it —
        // so without this check a captured request replays for as long as the
        // secret lives.
        if (abs(time() - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $this->secret);

        return hash_equals($expected, $presented);
    }

    /**
     * @return array{0: ?string, 1: ?string} [t, v1]
     */
    protected function parse(string $header): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            match (trim((string) $key)) {
                't' => $timestamp = trim((string) $value),
                'v1' => $signature = trim((string) $value),
                default => null,
            };
        }

        return [$timestamp ?: null, $signature ?: null];
    }
}
