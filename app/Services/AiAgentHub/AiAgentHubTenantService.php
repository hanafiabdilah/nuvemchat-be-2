<?php

namespace App\Services\AiAgentHub;

use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubTenant;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-scoped operations against the AI Agent Hub.
 *
 * Uses the tenant's active API key for auth (not the admin token).
 * See `AiAgentHubService` for admin-level provisioning of tenants & keys.
 */
class AiAgentHubTenantService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai_agent_hub.base_url'), '/');
    }

    /* ------------------------------------------------------------------
     | Models (public — no auth)
     * ------------------------------------------------------------------ */

    /**
     * List all provider models available on the hub. Public endpoint.
     */
    public function listModels(): array
    {
        $response = Http::acceptJson()->get("{$this->baseUrl}/models");

        if (!$response->successful()) {
            Log::error('AiAgentHubTenantService: Failed to list models', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception('Failed to list AI Agent Hub models: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    /* ------------------------------------------------------------------
     | Provider Credentials
     * ------------------------------------------------------------------ */

    /**
     * Fetch provider credentials for the tenant from the hub (live data).
     */
    public function listProviderCredentials(AiHubTenant $tenant): array
    {
        $response = Http::withHeaders($this->headers($tenant))
            ->get("{$this->baseUrl}/provider-credentials");

        $this->ensureSuccessful($response, 'list provider credentials', [
            'ai_hub_tenant_id' => $tenant->id,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Create a provider credential on the hub and persist it locally.
     *
     * $payload keys (per hub spec): provider, name, apiKey, defaultModel, metadata
     * Note: `apiKey` is forwarded to the hub but NOT stored locally —
     * we only retain the hub-returned `keyPreview`.
     */
    public function createProviderCredential(AiHubTenant $tenant, array $payload): AiHubProviderCredential
    {
        $response = Http::withHeaders($this->headers($tenant))
            ->post("{$this->baseUrl}/provider-credentials", $payload);

        $this->ensureSuccessful($response, 'create provider credential', [
            'ai_hub_tenant_id' => $tenant->id,
            'provider' => $payload['provider'] ?? null,
        ]);

        $data = $response->json();

        /** @var AiHubProviderCredential $credential */
        $credential = $tenant->providerCredentials()->create([
            'hub_provider_credential_id' => $data['id'] ?? null,
            'provider' => $data['provider'] ?? ($payload['provider'] ?? null),
            'name' => $data['name'] ?? ($payload['name'] ?? null),
            'key_preview' => $data['keyPreview'] ?? null,
            'default_model' => $data['defaultModel'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'metadata' => $data['metadata'] ?? null,
        ]);

        Log::info('AiAgentHubTenantService: Provider credential created', [
            'ai_hub_tenant_id' => $tenant->id,
            'provider' => $credential->provider,
            'hub_provider_credential_id' => $credential->hub_provider_credential_id,
        ]);

        return $credential;
    }

    /**
     * Update a provider credential on the hub and sync the local record.
     *
     * $payload may include: name, apiKey, defaultModel, status, metadata
     */
    public function updateProviderCredential(AiHubProviderCredential $credential, array $payload): AiHubProviderCredential
    {
        $tenant = $credential->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->patch("{$this->baseUrl}/provider-credentials/{$credential->hub_provider_credential_id}", $payload);

        $this->ensureSuccessful($response, 'update provider credential', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_provider_credential_id' => $credential->hub_provider_credential_id,
        ]);

        $data = $response->json();

        $credential->update(array_filter([
            'name' => $data['name'] ?? null,
            'key_preview' => $data['keyPreview'] ?? null,
            'default_model' => $data['defaultModel'] ?? null,
            'status' => $data['status'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        return $credential->refresh();
    }

    /**
     * Disable a provider credential on the hub.
     */
    public function disableProviderCredential(AiHubProviderCredential $credential): AiHubProviderCredential
    {
        return $this->updateProviderCredential($credential, ['status' => 'DISABLED']);
    }

    /* ------------------------------------------------------------------
     | Agents
     * ------------------------------------------------------------------ */

    /**
     * Fetch agents for the tenant from the hub (live data).
     */
    public function listAgents(AiHubTenant $tenant): array
    {
        $response = Http::withHeaders($this->headers($tenant))
            ->get("{$this->baseUrl}/agents");

        $this->ensureSuccessful($response, 'list agents', [
            'ai_hub_tenant_id' => $tenant->id,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Create an agent on the hub and persist it locally.
     *
     * $payload keys (per hub spec): externalId, name, description,
     * providerCredentialId (hub id), model, systemPrompt, temperature,
     * maxTokens, status, handoffRules, metadata
     */
    public function createAgent(AiHubTenant $tenant, array $payload): AiHubAgent
    {
        $response = Http::withHeaders($this->headers($tenant))
            ->post("{$this->baseUrl}/agents", $payload);

        $this->ensureSuccessful($response, 'create agent', [
            'ai_hub_tenant_id' => $tenant->id,
            'external_id' => $payload['externalId'] ?? null,
        ]);

        $data = $response->json();

        $localProviderCredentialId = null;
        if (!empty($data['providerCredentialId'])) {
            $localProviderCredentialId = AiHubProviderCredential::query()
                ->where('ai_hub_tenant_id', $tenant->id)
                ->where('hub_provider_credential_id', $data['providerCredentialId'])
                ->value('id');
        }

        /** @var AiHubAgent $agent */
        $agent = $tenant->agents()->create([
            'ai_hub_provider_credential_id' => $localProviderCredentialId,
            'hub_agent_id' => $data['id'] ?? null,
            'external_id' => $data['externalId'] ?? null,
            'name' => $data['name'] ?? ($payload['name'] ?? null),
            'description' => $data['description'] ?? null,
            'model' => $data['model'] ?? null,
            'system_prompt' => $data['systemPrompt'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'max_tokens' => $data['maxTokens'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'handoff_rules' => $data['handoffRules'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        Log::info('AiAgentHubTenantService: Agent created', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'external_id' => $agent->external_id,
        ]);

        return $agent;
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Resolve the tenant's active API key (decrypted via cast).
     */
    protected function resolveApiKey(AiHubTenant $tenant): string
    {
        $apiKey = $tenant->activeApiKey()->first();

        if (!$apiKey) {
            throw new Exception(
                "AiHubTenant {$tenant->id} has no active API key. Call AiAgentHubService::ensureProvisioned() first."
            );
        }

        return $apiKey->api_key;
    }

    /**
     * Tenant-scoped headers. Auth uses the tenant's active API key as a
     * Bearer token. Adjust here if the hub expects a different header
     * (e.g. `x-hub-api-key`).
     */
    protected function headers(AiHubTenant $tenant): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->resolveApiKey($tenant),
            'Accept' => 'application/json',
        ];
    }

    protected function ensureSuccessful($response, string $action, array $context = []): void
    {
        if ($response->successful()) {
            return;
        }

        Log::error("AiAgentHubTenantService: Failed to {$action}", array_merge($context, [
            'status' => $response->status(),
            'body' => $response->body(),
        ]));

        throw new Exception("Failed to {$action}: " . $response->body());
    }
}
