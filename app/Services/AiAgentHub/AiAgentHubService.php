<?php

namespace App\Services\AiAgentHub;

use App\Models\AiHubApiKey;
use App\Models\AiHubTenant;
use App\Models\Tenant;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAgentHubService
{
    /**
     * Seconds the lock holder can keep the lock before it auto-releases.
     * Should comfortably exceed the worst-case hub round-trip.
     */
    protected const LOCK_TTL = 30;

    /**
     * Seconds to wait when blocking on the lock before giving up.
     */
    protected const LOCK_WAIT = 10;

    protected string $baseUrl;
    protected ?string $adminToken;

    public function __construct()
    {
        $this->baseUrl = AiAgentHubConfig::baseUrl();
        $this->adminToken = AiAgentHubConfig::adminToken();
    }

    /**
     * Register the given local Tenant on the AI Agent Hub and persist an
     * `AiHubTenant` record. Builds externalId & name as `{app_name}_{tenant_id}`.
     * Idempotent: returns the existing AiHubTenant if one is already linked.
     * Wrapped in a per-tenant atomic lock to prevent duplicate registrations.
     */
    public function createTenant(Tenant $tenant): AiHubTenant
    {
        return Cache::lock($this->tenantLockKey($tenant), self::LOCK_TTL)
            ->block(self::LOCK_WAIT, function () use ($tenant) {
                $existing = $tenant->aiHubTenant()->first();

                if ($existing) {
                    return $existing;
                }

                $identifier = $this->buildTenantIdentifier($tenant);

                $payload = [
                    'externalId' => $identifier,
                    'name' => $identifier,
                    'metadata' => [
                        'source' => config('app.name'),
                        'tenant_id' => $tenant->id,
                    ],
                ];

                $response = Http::withHeaders($this->headers())
                    ->post("{$this->baseUrl}/admin/tenants", $payload);

                if (!$response->successful()) {
                    Log::error('AiAgentHubService: Failed to create tenant', [
                        'tenant_id' => $tenant->id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    throw new Exception('Failed to create AI Agent Hub tenant: ' . $response->body());
                }

                $data = $response->json();

                /** @var AiHubTenant $aiHubTenant */
                $aiHubTenant = $tenant->aiHubTenant()->create([
                    'hub_tenant_id' => $data['id'] ?? null,
                    'external_id' => $data['externalId'] ?? $identifier,
                    'name' => $data['name'] ?? $identifier,
                    'status' => $data['status'] ?? 'ACTIVE',
                    'metadata' => $data['metadata'] ?? null,
                ]);

                Log::info('AiAgentHubService: Tenant created', [
                    'tenant_id' => $tenant->id,
                    'ai_hub_tenant_id' => $aiHubTenant->id,
                    'hub_tenant_id' => $aiHubTenant->hub_tenant_id,
                ]);

                return $aiHubTenant;
            });
    }

    /**
     * Create an API key on the AI Agent Hub for the given AiHubTenant and
     * persist it as an `AiHubApiKey`. Idempotent: returns the existing ACTIVE
     * key if one is present. Wrapped in a per-AiHubTenant atomic lock.
     */
    public function createApiKey(AiHubTenant $aiHubTenant, ?string $name = null): AiHubApiKey
    {
        return Cache::lock($this->apiKeyLockKey($aiHubTenant), self::LOCK_TTL)
            ->block(self::LOCK_WAIT, function () use ($aiHubTenant, $name) {
                $existing = $aiHubTenant->apiKeys()
                    ->where('status', 'ACTIVE')
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $payload = [
                    'name' => $name ?? (string) config('app.name'),
                ];

                $response = Http::withHeaders($this->headers())
                    ->post("{$this->baseUrl}/admin/tenants/{$aiHubTenant->hub_tenant_id}/api-keys", $payload);

                if (!$response->successful()) {
                    Log::error('AiAgentHubService: Failed to create API key', [
                        'ai_hub_tenant_id' => $aiHubTenant->id,
                        'hub_tenant_id' => $aiHubTenant->hub_tenant_id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    throw new Exception('Failed to create AI Agent Hub API key: ' . $response->body());
                }

                $data = $response->json();

                /** @var AiHubApiKey $apiKey */
                $apiKey = $aiHubTenant->apiKeys()->create([
                    'hub_api_key_id' => $data['id'] ?? null,
                    'name' => $data['name'] ?? null,
                    'key_preview' => $data['keyPreview'] ?? null,
                    'api_key' => $data['apiKey'] ?? null,
                    'status' => $data['status'] ?? 'ACTIVE',
                    'expires_at' => $data['expiresAt'] ?? null,
                ]);

                Log::info('AiAgentHubService: API key created', [
                    'ai_hub_tenant_id' => $aiHubTenant->id,
                    'hub_tenant_id' => $aiHubTenant->hub_tenant_id,
                    'hub_api_key_id' => $apiKey->hub_api_key_id,
                    'key_preview' => $apiKey->key_preview,
                ]);

                return $apiKey;
            });
    }

    /**
     * Ensure the tenant has both an AiHubTenant and an ACTIVE API key,
     * creating whichever are missing. Safe to call repeatedly. Returns
     * the active API key (ready to use against the hub).
     */
    public function ensureProvisioned(Tenant $tenant, ?string $apiKeyName = null): AiHubApiKey
    {
        $aiHubTenant = $this->createTenant($tenant);

        return $this->createApiKey($aiHubTenant, $apiKeyName);
    }

    /**
     * Build the `{app_name}_{tenant_id}` identifier used for externalId & name.
     */
    protected function buildTenantIdentifier(Tenant $tenant): string
    {
        $appName = (string) config('app.name');

        return "{$appName}_{$tenant->id}";
    }

    /**
     * Default headers including the admin token for hub admin endpoints.
     */
    protected function headers(): array
    {
        return [
            'x-hub-admin-token' => $this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    protected function tenantLockKey(Tenant $tenant): string
    {
        return "ai_hub:create_tenant:{$tenant->id}";
    }

    protected function apiKeyLockKey(AiHubTenant $aiHubTenant): string
    {
        return "ai_hub:create_api_key:{$aiHubTenant->id}";
    }
}
