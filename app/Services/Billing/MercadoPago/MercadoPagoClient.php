<?php

namespace App\Services\Billing\MercadoPago;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin typed wrapper over the MercadoPago REST API using Laravel's Http client.
 * We only need a handful of endpoints (preapproval + payment), so we avoid the
 * heavyweight mercadopago/dx-php SDK and stay consistent with the codebase's
 * other Http-facade integrations.
 */
class MercadoPagoClient
{
    public function __construct(
        protected ?string $accessToken = null,
        protected ?string $baseUrl = null,
    ) {
        $this->accessToken ??= MercadoPagoConfig::accessToken();
        $this->baseUrl ??= rtrim(MercadoPagoConfig::baseUrl(), '/');
    }

    protected function http(?string $idempotencyKey = null): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30);

        if ($idempotencyKey) {
            $request = $request->withHeaders(['X-Idempotency-Key' => $idempotencyKey]);
        }

        return $request;
    }

    /**
     * Create a recurring card subscription (Assinaturas / preapproval).
     *
     * @param  array  $payload  reason, auto_recurring, card_token_id, payer_email, back_url, external_reference, status
     */
    public function createPreapproval(array $payload, string $idempotencyKey): array
    {
        return $this->http($idempotencyKey)
            ->post('/preapproval', $payload)
            ->throw()
            ->json();
    }

    public function getPreapproval(string $id): array
    {
        return $this->http()->get("/preapproval/{$id}")->throw()->json();
    }

    public function cancelPreapproval(string $id): array
    {
        return $this->http()
            ->put("/preapproval/{$id}", ['status' => 'cancelled'])
            ->throw()
            ->json();
    }

    public function updatePreapproval(string $id, array $payload): array
    {
        return $this->http()
            ->put("/preapproval/{$id}", $payload)
            ->throw()
            ->json();
    }

    /**
     * Create a one-off Pix payment. Returns the QR / copy-paste in
     * point_of_interaction.transaction_data.
     */
    public function createPixPayment(array $payload, string $idempotencyKey): array
    {
        return $this->http($idempotencyKey)
            ->post('/v1/payments', $payload)
            ->throw()
            ->json();
    }

    public function getPayment(string $id): array
    {
        return $this->http()->get("/v1/payments/{$id}")->throw()->json();
    }
}
