<?php

namespace App\Services\AiAgentHub;

use App\Enums\Connection\Channel;
use App\Models\AiHubAgent;
use App\Models\AiHubAgentProfile;
use App\Models\AiHubKnowledge;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubRun;
use App\Models\AiHubSkill;
use App\Models\AiHubTenant;
use App\Models\AiHubTrainingExample;
use App\Models\Conversation;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $this->baseUrl = AiAgentHubConfig::baseUrl();
    }

    /* ------------------------------------------------------------------
     | Models
     * ------------------------------------------------------------------ */

    /**
     * List all provider models available on the hub. Authenticated with
     * the tenant's active API key.
     */
    public function listModels(AiHubTenant $tenant): array
    {
        $response = Http::withHeaders($this->headers($tenant))
            ->get("{$this->baseUrl}/models");

        $this->ensureSuccessful($response, 'list models', [
            'ai_hub_tenant_id' => $tenant->id,
        ]);

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
        $payload['metadata'] = [
            'billingMode' => 'customer_token',
            'ownerType' => 'customer',
        ];

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

    /**
     * Delete a provider credential on the hub and locally.
     * Assumes the hub exposes DELETE /provider-credentials/{id}.
     */
    public function deleteProviderCredential(AiHubProviderCredential $credential): void
    {
        $tenant = $credential->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->delete("{$this->baseUrl}/provider-credentials/{$credential->hub_provider_credential_id}");

        $this->ensureSuccessful($response, 'delete provider credential', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_provider_credential_id' => $credential->hub_provider_credential_id,
        ]);

        $credential->delete();
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
     *
     * Note: `externalId` is always wrapped with the app-name prefix to
     * satisfy the hub's ≥ 2 character constraint and to namespace IDs
     * across apps (same pattern as the tenant externalId).
     */
    public function createAgent(AiHubTenant $tenant, array $payload): AiHubAgent
    {
        $payload['externalId'] = $this->buildExternalId(
            $this->normalizeAgentExternalId($payload['externalId'] ?? null, $payload['name'] ?? null)
        );

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

    /**
     * Update an agent on the hub and sync the local record.
     * Assumes the hub exposes PATCH /agents/{id}.
     *
     * Note: `externalId` (when sent) is wrapped with the app-name prefix —
     * same constraint as createAgent.
     */
    public function updateAgent(AiHubAgent $agent, array $payload): AiHubAgent
    {
        if (isset($payload['externalId'])) {
            $payload['externalId'] = $this->buildExternalId($payload['externalId']);
        }

        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->patch("{$this->baseUrl}/agents/{$agent->hub_agent_id}", $payload);

        $this->ensureSuccessful($response, 'update agent', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $data = $response->json();

        $localProviderCredentialId = $agent->ai_hub_provider_credential_id;
        if (!empty($data['providerCredentialId'])) {
            $localProviderCredentialId = AiHubProviderCredential::query()
                ->where('ai_hub_tenant_id', $tenant->id)
                ->where('hub_provider_credential_id', $data['providerCredentialId'])
                ->value('id') ?? $localProviderCredentialId;
        }

        $agent->update(array_filter([
            'ai_hub_provider_credential_id' => $localProviderCredentialId,
            'external_id' => $data['externalId'] ?? null,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'model' => $data['model'] ?? null,
            'system_prompt' => $data['systemPrompt'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'max_tokens' => $data['maxTokens'] ?? null,
            'status' => $data['status'] ?? null,
            'handoff_rules' => $data['handoffRules'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        return $agent->refresh();
    }

    /**
     * Delete an agent on the hub and locally.
     * Assumes the hub exposes DELETE /agents/{id}.
     */
    public function deleteAgent(AiHubAgent $agent): void
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}");

        $this->ensureSuccessful($response, 'delete agent', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $agent->delete();
    }

    /* ------------------------------------------------------------------
     | Agent Training — Profile
     * ------------------------------------------------------------------ */

    /**
     * Upsert the operational profile for an agent (language, tone,
     * response style, instructions, limits). Mirrors the hub response
     * into the local AiHubAgentProfile (1-to-1 with the agent).
     *
     * Payload keys (per hub spec): language, tone, responseStyle,
     * instructions (array), limits (array), metadata (object).
     */
    public function setAgentProfile(AiHubAgent $agent, array $payload): AiHubAgentProfile
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->put("{$this->baseUrl}/agents/{$agent->hub_agent_id}/profile", $payload);

        $this->ensureSuccessful($response, 'set agent profile', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $data = $response->json() ?? [];

        $profile = AiHubAgentProfile::updateOrCreate(
            ['ai_hub_agent_id' => $agent->id],
            [
                'language' => $data['language'] ?? ($payload['language'] ?? null),
                'tone' => $data['tone'] ?? ($payload['tone'] ?? null),
                'response_style' => $data['responseStyle'] ?? ($payload['responseStyle'] ?? null),
                'instructions' => $data['instructions'] ?? ($payload['instructions'] ?? null),
                'limits' => $data['limits'] ?? ($payload['limits'] ?? null),
                'metadata' => $data['metadata'] ?? ($payload['metadata'] ?? null),
            ]
        );

        Log::info('AiAgentHubTenantService: Agent profile upserted', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'ai_hub_agent_profile_id' => $profile->id,
        ]);

        return $profile->fresh();
    }

    /* ------------------------------------------------------------------
     | Agent Training — Knowledge
     * ------------------------------------------------------------------ */

    /**
     * Fetch the live knowledge list from the hub (read-through, no sync).
     */
    public function listAgentKnowledge(AiHubAgent $agent): array
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->get("{$this->baseUrl}/agents/{$agent->hub_agent_id}/knowledge");

        $this->ensureSuccessful($response, 'list agent knowledge', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Create a knowledge item on the hub and persist it locally.
     *
     * Payload keys (per hub spec): title, content, tags (array),
     * metadata (object).
     */
    public function createAgentKnowledge(AiHubAgent $agent, array $payload): AiHubKnowledge
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->post("{$this->baseUrl}/agents/{$agent->hub_agent_id}/knowledge", $payload);

        $this->ensureSuccessful($response, 'create agent knowledge', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $data = $response->json() ?? [];

        /** @var AiHubKnowledge $knowledge */
        $knowledge = $agent->knowledge()->create([
            'hub_knowledge_id' => $data['id'] ?? null,
            'title' => $data['title'] ?? ($payload['title'] ?? null),
            'content' => $data['content'] ?? ($payload['content'] ?? null),
            'tags' => $data['tags'] ?? ($payload['tags'] ?? null),
            'metadata' => $data['metadata'] ?? ($payload['metadata'] ?? null),
        ]);

        Log::info('AiAgentHubTenantService: Agent knowledge created', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'hub_knowledge_id' => $knowledge->hub_knowledge_id,
        ]);

        return $knowledge;
    }

    /**
     * Update a knowledge item on the hub and sync the local record.
     *
     * If the hub returns status=DISABLED, the local row is hard-deleted
     * to keep the local table reflecting only active knowledge.
     */
    public function updateAgentKnowledge(AiHubKnowledge $knowledge, array $payload): ?AiHubKnowledge
    {
        $agent = $knowledge->aiHubAgent;
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->patch("{$this->baseUrl}/agents/{$agent->hub_agent_id}/knowledge/{$knowledge->hub_knowledge_id}", $payload);

        $this->ensureSuccessful($response, 'update agent knowledge', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_knowledge_id' => $knowledge->hub_knowledge_id,
        ]);

        $data = $response->json() ?? [];

        if (($data['status'] ?? null) === 'DISABLED') {
            $knowledge->delete();
            return null;
        }

        $knowledge->update(array_filter([
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'tags' => $data['tags'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        return $knowledge->refresh();
    }

    /**
     * Disable a knowledge item on the hub (hub keeps it with status
     * DISABLED) and hard-delete the local mirror.
     */
    public function deleteAgentKnowledge(AiHubKnowledge $knowledge): void
    {
        $agent = $knowledge->aiHubAgent;
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}/knowledge/{$knowledge->hub_knowledge_id}");

        $this->ensureSuccessful($response, 'delete agent knowledge', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_knowledge_id' => $knowledge->hub_knowledge_id,
        ]);

        $knowledge->delete();
    }

    /* ------------------------------------------------------------------
     | Agent Training — Skills
     * ------------------------------------------------------------------ */

    /**
     * Fetch the live skills list from the hub (read-through, no sync).
     */
    public function listAgentSkills(AiHubAgent $agent): array
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->get("{$this->baseUrl}/agents/{$agent->hub_agent_id}/skills");

        $this->ensureSuccessful($response, 'list agent skills', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Create a skill on the hub and persist it locally.
     *
     * Payload keys (per hub spec): name, description, instructions
     * (array), metadata (object).
     */
    public function createAgentSkill(AiHubAgent $agent, array $payload): AiHubSkill
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->post("{$this->baseUrl}/agents/{$agent->hub_agent_id}/skills", $payload);

        $this->ensureSuccessful($response, 'create agent skill', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $data = $response->json() ?? [];

        /** @var AiHubSkill $skill */
        $skill = $agent->skills()->create([
            'hub_skill_id' => $data['id'] ?? null,
            'name' => $data['name'] ?? ($payload['name'] ?? null),
            'description' => $data['description'] ?? ($payload['description'] ?? null),
            'instructions' => $data['instructions'] ?? ($payload['instructions'] ?? null),
            'metadata' => $data['metadata'] ?? ($payload['metadata'] ?? null),
        ]);

        Log::info('AiAgentHubTenantService: Agent skill created', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'hub_skill_id' => $skill->hub_skill_id,
        ]);

        return $skill;
    }

    /**
     * Update a skill on the hub and sync the local record.
     *
     * If the hub returns status=DISABLED, the local row is hard-deleted
     * to keep the local table reflecting only active skills.
     */
    public function updateAgentSkill(AiHubSkill $skill, array $payload): ?AiHubSkill
    {
        $agent = $skill->aiHubAgent;
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->patch("{$this->baseUrl}/agents/{$agent->hub_agent_id}/skills/{$skill->hub_skill_id}", $payload);

        $this->ensureSuccessful($response, 'update agent skill', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_skill_id' => $skill->hub_skill_id,
        ]);

        $data = $response->json() ?? [];

        if (($data['status'] ?? null) === 'DISABLED') {
            $skill->delete();
            return null;
        }

        $skill->update(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        return $skill->refresh();
    }

    /**
     * Disable a skill on the hub (hub keeps it with status DISABLED) and
     * hard-delete the local mirror.
     */
    public function deleteAgentSkill(AiHubSkill $skill): void
    {
        $agent = $skill->aiHubAgent;
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}/skills/{$skill->hub_skill_id}");

        $this->ensureSuccessful($response, 'delete agent skill', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_skill_id' => $skill->hub_skill_id,
        ]);

        $skill->delete();
    }

    /* ------------------------------------------------------------------
     | Agent Training — Training Examples
     * ------------------------------------------------------------------ */

    /**
     * Fetch the live training examples list from the hub (read-through,
     * no sync).
     */
    public function listAgentTrainingExamples(AiHubAgent $agent): array
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->get("{$this->baseUrl}/agents/{$agent->hub_agent_id}/training-examples");

        $this->ensureSuccessful($response, 'list agent training examples', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Create a training example on the hub and persist it locally.
     *
     * Payload keys (per hub spec): type, input, expectedOutput, notes,
     * metadata.
     */
    public function createAgentTrainingExample(AiHubAgent $agent, array $payload): AiHubTrainingExample
    {
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->post("{$this->baseUrl}/agents/{$agent->hub_agent_id}/training-examples", $payload);

        $this->ensureSuccessful($response, 'create agent training example', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $data = $response->json() ?? [];

        /** @var AiHubTrainingExample $example */
        $example = $agent->trainingExamples()->create([
            'hub_example_id' => $data['id'] ?? null,
            'type' => $data['type'] ?? ($payload['type'] ?? 'style_example'),
            'input' => $data['input'] ?? ($payload['input'] ?? null),
            'expected_output' => $data['expectedOutput'] ?? ($payload['expectedOutput'] ?? null),
            'notes' => $data['notes'] ?? ($payload['notes'] ?? null),
            'metadata' => $data['metadata'] ?? ($payload['metadata'] ?? null),
        ]);

        Log::info('AiAgentHubTenantService: Agent training example created', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'hub_example_id' => $example->hub_example_id,
        ]);

        return $example;
    }

    /**
     * Update a training example on the hub and sync the local record.
     *
     * If the hub returns status=DISABLED, the local row is hard-deleted
     * to keep the local table reflecting only active examples.
     */
    public function updateAgentTrainingExample(AiHubTrainingExample $example, array $payload): ?AiHubTrainingExample
    {
        $agent = $example->aiHubAgent;
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->patch("{$this->baseUrl}/agents/{$agent->hub_agent_id}/training-examples/{$example->hub_example_id}", $payload);

        $this->ensureSuccessful($response, 'update agent training example', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_example_id' => $example->hub_example_id,
        ]);

        $data = $response->json() ?? [];

        if (($data['status'] ?? null) === 'DISABLED') {
            $example->delete();
            return null;
        }

        $example->update(array_filter([
            'type' => $data['type'] ?? null,
            'input' => $data['input'] ?? null,
            'expected_output' => $data['expectedOutput'] ?? null,
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        return $example->refresh();
    }

    /**
     * Disable a training example on the hub (hub keeps it with status
     * DISABLED) and hard-delete the local mirror.
     */
    public function deleteAgentTrainingExample(AiHubTrainingExample $example): void
    {
        $agent = $example->aiHubAgent;
        $tenant = $agent->aiHubTenant;

        $response = Http::withHeaders($this->headers($tenant))
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}/training-examples/{$example->hub_example_id}");

        $this->ensureSuccessful($response, 'delete agent training example', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_example_id' => $example->hub_example_id,
        ]);

        $example->delete();
    }

    /* ------------------------------------------------------------------
     | Runs (Agent Execution)
     * ------------------------------------------------------------------ */

    /**
     * Run an agent synchronously against a conversation and persist the
     * resulting hub run record locally for billing/observability.
     *
     * The hub maintains its own conversation state keyed by
     * `conversation.externalId`, so we only forward the latest user
     * message — history is tracked hub-side.
     *
     * The caller is responsible for delivering the AI reply to the contact
     * (via MessageService) and linking the produced Message back to the
     * AiHubRun by setting `message_id`.
     */
    public function runAgent(
        AiHubAgent $agent,
        Conversation $conversation,
        string $userMessage,
        ?int $flowStateId = null,
        ?int $flowNodeId = null,
        array $metadata = []
    ): AiHubRun {
        $tenant = $agent->aiHubTenant;
        $conversation->loadMissing(['contact', 'connection']);

        $payload = [
            'agentExternalId' => $agent->external_id,
            'responseMode' => 'sync',
            'conversation' => [
                'externalId' => $conversation->external_id,
                'channel' => $this->mapChannelForHub($conversation->connection->channel),
                'contactExternalId' => $conversation->contact->external_id,
                'contactName' => $conversation->contact->name,
            ],
            'message' => [
                'role' => 'USER',
                'content' => $userMessage,
            ],
        ];

        if (!empty($metadata)) {
            $payload['metadata'] = $metadata;
        }

        $response = Http::withHeaders($this->headers($tenant))
            ->post("{$this->baseUrl}/runs", $payload);

        $this->ensureSuccessful($response, 'run agent', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'conversation_id' => $conversation->id,
        ]);

        $data = $response->json() ?? [];

        Log::info('AiAgentHubTenantService: Agent run completed', [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'conversation_id' => $conversation->id,
            'hub_run_id' => $data['id'] ?? null,
            'response' => $data,
        ]);

        return $this->persistRun(
            $agent,
            $conversation,
            $userMessage,
            $data,
            $flowStateId,
            $flowNodeId,
            $metadata
        );
    }

    /**
     * Map our internal Channel enum to the channel string the hub expects.
     * Both WhatsApp variants collapse to a single "whatsapp" identifier.
     */
    protected function mapChannelForHub(Channel $channel): string
    {
        return match ($channel) {
            Channel::Instagram => 'instagram',
            Channel::WhatsappOfficial, Channel::WhatsappWApi, Channel::WhatsappProxyhub => 'whatsapp',
            Channel::Telegram => 'telegram',
            Channel::LiveChatWidget => 'live_chat_widget',
        };
    }

    /**
     * Persist a hub run response as a local AiHubRun for billing and
     * observability. Latency is derived from hub-reported timestamps when
     * available.
     */
    protected function persistRun(
        AiHubAgent $agent,
        Conversation $conversation,
        string $userMessage,
        array $data,
        ?int $flowStateId,
        ?int $flowNodeId,
        array $metadata
    ): AiHubRun {
        $output = $data['output'] ?? [];
        $usage = $output['usage'] ?? [];
        $cost = $output['cost'] ?? [];

        $startedAt = !empty($data['startedAt']) ? Carbon::parse($data['startedAt']) : null;
        $completedAt = !empty($data['completedAt']) ? Carbon::parse($data['completedAt']) : null;
        $latencyMs = ($startedAt && $completedAt)
            ? (int) $startedAt->diffInMilliseconds($completedAt)
            : null;

        return AiHubRun::create([
            'tenant_id' => $conversation->contact->tenant_id,
            'ai_hub_agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'flow_state_id' => $flowStateId,
            'flow_node_id' => $flowNodeId,
            'message_id' => null,
            'hub_run_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? 'UNKNOWN',
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'input_message' => $userMessage,
            'output_message' => $output['message'] ?? null,
            'handoff_triggered' => (bool) ($output['handoff'] ?? false),
            'handoff_details' => $output['handoffDetails'] ?? null,
            'input_tokens' => $usage['inputTokens'] ?? 0,
            'cached_input_tokens' => $usage['cachedInputTokens'] ?? 0,
            'output_tokens' => $usage['outputTokens'] ?? 0,
            'total_tokens' => $usage['totalTokens'] ?? 0,
            'cost_usd' => $data['providerCostUsd'] ?? ($cost['usd'] ?? null),
            'cost_currency' => $data['providerCostCurrency'] ?? ($cost['currency'] ?? null),
            'cost_breakdown' => $data['providerCostBreakdown'] ?? ($cost ?: null),
            'error' => $data['error'] ?? null,
            'metadata' => $metadata ?: null,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'latency_ms' => $latencyMs,
        ]);
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Wrap a user-supplied external id with the app-name prefix.
     * Mirrors `AiAgentHubService::buildTenantIdentifier()` — guarantees
     * the hub's ≥ 2 character constraint on `externalId` and namespaces
     * IDs across apps sharing the same hub.
     */
    protected function buildExternalId(string $externalId): string
    {
        $appName = (string) config('app.name');

        return "{$appName}_{$externalId}";
    }

    /**
     * Produce a non-empty agent external id. Falls back to a slugified
     * name + short random suffix when the caller doesn't supply one
     * (or supplies an empty/non-string value), so the hub never receives
     * a missing/invalid `externalId`.
     */
    protected function normalizeAgentExternalId(mixed $externalId, ?string $name): string
    {
        if (is_string($externalId) && $externalId !== '') {
            return $externalId;
        }

        $base = Str::slug($name ?? '');

        if ($base === '') {
            $base = 'agent';
        }

        return $base . '-' . Str::lower(Str::random(8));
    }

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

    protected function ensureSuccessful(Response $response, string $action, array $context = []): void
    {
        if ($response->successful()) {
            return;
        }

        if($response->status() === 400){
            Log::warning("AiAgentHubTenantService: Validation failed to {$action}", array_merge($context, [
                'status' => $response->status(),
                'body' => $response->body(),
            ]));

            throw ValidationException::withMessages(['message' => $response->json()['message'][0] ?? 'Bad Request']);
        }elseif($response->status() === 409){
            Log::warning("AiAgentHubTenantService: Conflict occurred trying to {$action}", array_merge($context, [
                'status' => $response->status(),
                'body' => $response->body(),
            ]));

            throw new Exception($response->json()['message'] ?? 'Conflict', 409);
        }

        Log::error("AiAgentHubTenantService: Failed to {$action}", array_merge($context, [
            'status' => $response->status(),
            'body' => $response->body(),
        ]));

        throw new Exception("Failed to {$action}");
    }
}
