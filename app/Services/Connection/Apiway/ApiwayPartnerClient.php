<?php

namespace App\Services\Connection\Apiway;

use App\Exceptions\ApiwayPartnerException;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the ProxyBR partner API (/api/partner/v1/apiway/*).
 *
 * Money never moves at ProxyBR: this surface only provisions, renews and
 * cancels subscriptions and mirrors the per-instance console. All billing
 * happens on our side before these calls are made. Create is idempotent via
 * `external_ref`, renew via the `Idempotency-Key` header — which is what makes
 * the transport-level retries below safe.
 */
class ApiwayPartnerClient
{
    public function isConfigured(): bool
    {
        return ApiwayConfig::partnerToken() !== null;
    }

    /** Catalog: settings (min/max, annual discount), price tiers and locations. */
    public function plans(): array
    {
        return $this->unwrap($this->request()->get($this->url('/plans')));
    }

    public function quote(int $quantity, string $locationCode, string $cycle): array
    {
        return $this->unwrap($this->request()->post($this->url('/quote'), [
            'quantity' => $quantity,
            'location_code' => $locationCode,
            'cycle' => $cycle,
        ]));
    }

    /**
     * Create a subscription (provisioning proxy + core instances). Replays of
     * the same external_ref return the original subscription with
     * meta.idempotent_replay = true, so the caller gets `data` and `meta`.
     */
    public function createSubscription(
        string $externalRef,
        array $externalUser,
        int $quantity,
        string $locationCode,
        string $cycle,
    ): array {
        $response = $this->request(timeout: 90)->post($this->url('/subscriptions'), [
            'external_ref' => $externalRef,
            'external_user' => $externalUser,
            'quantity' => $quantity,
            'location_code' => $locationCode,
            'cycle' => $cycle,
        ]);

        $json = $this->decode($response);

        return ['data' => $json['data'], 'meta' => $json['meta'] ?? []];
    }

    public function getSubscription(int $providerId): array
    {
        return $this->unwrap($this->request()->get($this->url("/subscriptions/{$providerId}")));
    }

    public function listSubscriptions(array $query = []): array
    {
        return $this->decode($this->request()->get($this->url('/subscriptions'), $query));
    }

    /** Renew. Price is re-quoted by ProxyBR at call time; expiry extends from max(now, expires_at). */
    public function renewSubscription(int $providerId, string $idempotencyKey, ?string $cycle = null): array
    {
        $response = $this->request(timeout: 90)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post($this->url("/subscriptions/{$providerId}/renew"), array_filter(['cycle' => $cycle]));

        return $this->unwrap($response);
    }

    /** Permanent revoke (core instances deleted, proxies released). Idempotent on terminal states. */
    public function cancelSubscription(int $providerId): array
    {
        return $this->unwrap($this->request(timeout: 90)->post($this->url("/subscriptions/{$providerId}/cancel")));
    }

    // --- Instance console (id = core UUID) ---

    public function instanceDetail(string $instanceId): array
    {
        return $this->unwrap($this->request()->get($this->url("/instances/{$instanceId}")));
    }

    public function instanceStatus(string $instanceId): array
    {
        return $this->unwrap($this->request()->get($this->url("/instances/{$instanceId}/status")));
    }

    /** QR pairing: `qr_code` is base64 PNG; qr_pending_reason=qr_not_emitted_yet means keep polling. */
    public function instanceQr(string $instanceId): array
    {
        return $this->unwrap($this->request()->get($this->url("/instances/{$instanceId}/qr")));
    }

    /** Instance API token (plaintext + masked) used against the public core. */
    public function instanceToken(string $instanceId): array
    {
        return $this->unwrap($this->request()->get($this->url("/instances/{$instanceId}/token")));
    }

    public function getInstanceWebhook(string $instanceId): array
    {
        return $this->unwrap($this->request()->get($this->url("/instances/{$instanceId}/webhook")));
    }

    public function setInstanceWebhook(string $instanceId, string $webhookUrl, array $events = []): array
    {
        $payload = ['url' => $webhookUrl];

        // events must be a subset of available_events; omitting it keeps the
        // partner default (all events) when we couldn't read the catalog.
        if ($events !== []) {
            $payload['events'] = $events;
        }

        return $this->unwrap($this->request()->put($this->url("/instances/{$instanceId}/webhook"), $payload));
    }

    public function reconnectSession(string $instanceId): array
    {
        return $this->unwrap($this->request()->post($this->url("/instances/{$instanceId}/session/reconnect")));
    }

    public function disconnectSession(string $instanceId): array
    {
        return $this->unwrap($this->request()->post($this->url("/instances/{$instanceId}/session/disconnect"), [
            'confirm' => 'DESCONECTAR',
        ]));
    }

    // --- Plumbing ---

    protected function request(int $timeout = 30): PendingRequest
    {
        $token = ApiwayConfig::partnerToken();

        if ($token === null) {
            throw new ApiwayPartnerException(
                'ProxyBR partner token is not configured.',
                errorCode: 'apiway_unconfigured',
                httpStatus: 503,
            );
        }

        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout($timeout)
            ->retry(2, 1500, fn ($e) => $e instanceof HttpConnectionException, throw: false);
    }

    protected function url(string $path): string
    {
        return ApiwayConfig::partnerBaseUrl().'/api/partner/v1/apiway'.$path;
    }

    /** Decode + raise ApiwayPartnerException on the normalized {error, message} envelope. */
    protected function decode(Response $response): array
    {
        $json = $response->json();

        if ($response->failed() || ! is_array($json)) {
            throw new ApiwayPartnerException(
                $json['message'] ?? 'ProxyBR partner API request failed.',
                errorCode: $json['error'] ?? null,
                httpStatus: $response->status(),
            );
        }

        return $json;
    }

    protected function unwrap(Response $response): array
    {
        return $this->decode($response)['data'] ?? [];
    }
}
