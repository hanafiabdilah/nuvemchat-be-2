<?php

namespace App\Services\Billing\MercadoPago;

use Illuminate\Http\Request;

/**
 * Verifies the MercadoPago webhook signature.
 *
 * MercadoPago sends `x-signature: ts=<ts>,v1=<hmac>` and `x-request-id`.
 * The signed manifest is `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`
 * HMAC-SHA256'd with the webhook secret.
 *
 * @see https://www.mercadopago.com.br/developers/en/docs/your-integrations/notifications/webhooks
 */
class WebhookSignatureVerifier
{
    public function __construct(
        protected ?string $secret = null,
    ) {
        $this->secret ??= MercadoPagoConfig::webhookSecret();
    }

    public function verify(Request $request): bool
    {
        // If no secret configured, skip verification (e.g. local dev sandbox).
        if (empty($this->secret)) {
            return true;
        }

        $signature = $request->header('x-signature');
        $requestId = $request->header('x-request-id');
        $dataId = $request->query('data.id') ?? $request->input('data.id');

        if (! $signature || ! $dataId) {
            return false;
        }

        [$ts, $hash] = $this->parseSignature($signature);
        if (! $ts || ! $hash) {
            return false;
        }

        // data.id is lowercased in the manifest per MP spec.
        $manifest = sprintf(
            'id:%s;request-id:%s;ts:%s;',
            strtolower((string) $dataId),
            $requestId,
            $ts,
        );

        $expected = hash_hmac('sha256', $manifest, $this->secret);

        return hash_equals($expected, $hash);
    }

    /**
     * @return array{0:?string,1:?string} [ts, v1]
     */
    protected function parseSignature(string $signature): array
    {
        $ts = null;
        $hash = null;

        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            $key = trim((string) $key);
            $value = trim((string) $value);

            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $hash = $value;
            }
        }

        return [$ts, $hash];
    }
}
