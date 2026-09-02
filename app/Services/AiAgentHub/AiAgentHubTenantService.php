<?php

namespace App\Services\AiAgentHub;

use App\Enums\Billing\Quota;
use App\Enums\Connection\Channel;
use App\Exceptions\AiHubObjectMissingException;
use App\Exceptions\Billing\AiCreditExhaustedException;
use App\Exceptions\Billing\AiRunQuotaExceededException;
use App\Models\AiHubAgent;
use App\Models\AiHubAgentProfile;
use App\Models\AiHubKnowledge;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubRun;
use App\Models\AiHubSkill;
use App\Models\AiHubTenant;
use App\Models\AiHubTrainingExample;
use App\Models\Conversation;
use App\Services\AiCredits\AiCreditService;
use App\Services\Billing\SubscriptionGate;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workspace-scoped operations against the AI Agent Hub.
 *
 * The `AiHubTenant` threaded through these methods is a **customer workspace
 * of ours** — it says whose data a call concerns and where the local mirror
 * row belongs. It is not a hub identity and carries no credential: auth is
 * always the platform's own hub tenant token, because Pingly is a single
 * tenant of the hub. See {@see AiAgentHubConfig} for that distinction.
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
     * List all provider models available on the hub. The workspace is passed
     * for log context only — the catalogue is the same for every one of them.
     */
    public function listModels(?AiHubTenant $tenant = null): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/models");

        // Nullable since the platform tenant token authenticates every call:
        // the Back Office asks the same question with no workspace behind it.
        $this->ensureSuccessful($response, 'list models', [
            'ai_hub_tenant_id' => $tenant?->id,
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
        $response = Http::withHeaders($this->headers())
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
     *
     * `$metadata` overrides the default "this is the customer's own key"
     * marking. The rental service passes its own so the hub's record says whose
     * key it is holding; without the override every platform key would be
     * filed there as a customer's.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function createProviderCredential(AiHubTenant $tenant, array $payload, ?array $metadata = null): AiHubProviderCredential
    {
        $payload['metadata'] = $metadata ?? [
            'billingMode' => 'customer_token',
            'ownerType' => 'customer',
        ];

        // ElevenLabs is stored as a credential but refused as an agent's
        // provider — it exists here only to transcribe voice notes and to
        // speak replies. Saying so in the metadata keeps the hub's own record
        // honest about what the key is for.
        if (strtoupper((string) ($payload['provider'] ?? '')) === 'ELEVENLABS') {
            $payload['metadata']['usage'] = ['speech_to_text', 'text_to_speech'];
        }

        $response = Http::withHeaders($this->headers())
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

        $patch = function () use ($credential, $payload) {
            return Http::withHeaders($this->headers())
                ->patch("{$this->baseUrl}/provider-credentials/{$credential->hub_provider_credential_id}", $payload);
        };

        $response = $patch();

        // The hub lost the record — put it back and apply the edit to the new
        // one. Someone changing the name of their key has no idea an id exists,
        // let alone that it stopped resolving; an error here would be about our
        // bookkeeping, in their way.
        if ($response->status() === 404) {
            $this->repushProviderCredential($credential, $payload['apiKey'] ?? null);
            $credential->refresh();
            $response = $patch();
        }

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

        // After the write-back, not before: the hub echoes the metadata we gave
        // it, placeholder marking and all, so clearing the flag first would
        // simply be undone by the line above — and the badge would stay up on a
        // credential that has just been given a working key.
        if (! empty($payload['apiKey'])) {
            $this->markCredentialKeyed($credential);
        }

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

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/provider-credentials/{$credential->hub_provider_credential_id}");

        $this->ensureDeleted($response, 'delete provider credential', [
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
        $response = Http::withHeaders($this->headers())
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
            $this->normalizeAgentExternalId($payload['externalId'] ?? null, $payload['name'] ?? null),
            $tenant
        );

        $response = Http::withHeaders($this->headers())
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
        $tenant = $agent->aiHubTenant;

        if (isset($payload['externalId'])) {
            $payload['externalId'] = $this->buildExternalId($payload['externalId'], $tenant);
        }

        $response = $this->healingAgentCall(
            $agent,
            fn () => Http::withHeaders($this->headers())
                ->patch("{$this->baseUrl}/agents/{$agent->hub_agent_id}", $payload)
        );

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

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}");

        $this->ensureDeleted($response, 'delete agent', [
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

        $response = $this->healingAgentCall(
            $agent,
            fn () => Http::withHeaders($this->headers())
                ->put("{$this->baseUrl}/agents/{$agent->hub_agent_id}/profile", $payload)
        );

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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}/knowledge/{$knowledge->hub_knowledge_id}");

        $this->ensureDeleted($response, 'delete agent knowledge', [
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}/skills/{$skill->hub_skill_id}");

        $this->ensureDeleted($response, 'delete agent skill', [
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
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

        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/agents/{$agent->hub_agent_id}/training-examples/{$example->hub_example_id}");

        $this->ensureDeleted($response, 'delete agent training example', [
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
     *
     * $conversationExternalId overrides the hub-side conversation key. Use it
     * when the run must not touch the real conversation's hub state — e.g.
     * "Respond with AI" drafts, which run under a synthetic id.
     *
     * $attachments carries the media the agent should be given — screenshots
     * to look at, voice notes to transcribe — in the shape the hub documents
     * for `message.attachments`. Build it with AiAttachments rather than by
     * hand: the `inputAudio` block that makes a voice note more than a file is
     * derived from it here.
     *
     * $responseAudio asks the hub to speak the reply as well as write it. It
     * has to travel with the run — the voice is generated alongside the text,
     * so nothing can add it afterwards. Build it with AiVoiceReply, which owns
     * the question of when an agent should be speaking at all.
     */
    public function runAgent(
        AiHubAgent $agent,
        Conversation $conversation,
        string $userMessage,
        ?int $flowStateId = null,
        ?int $flowNodeId = null,
        array $metadata = [],
        ?string $conversationExternalId = null,
        array $attachments = [],
        array $responseAudio = [],
        array $inputAudio = []
    ): AiHubRun {
        $tenant = $agent->aiHubTenant;
        $conversation->loadMissing(['contact', 'connection']);

        $this->assertWithinRunQuota($agent);
        $this->assertCanSpendCredit($agent);

        // Recorded on the run so "did the agent actually see the screenshot /
        // hear the voice note?" is answerable afterwards from the row alone.
        $counts = array_count_values(array_column($attachments, 'type'));

        foreach (['image' => 'imageAttachments', 'audio' => 'audioAttachments'] as $type => $key) {
            if (isset($counts[$type])) {
                $metadata[$key] = $counts[$type];
            }
        }

        $payload = [
            'agentExternalId' => $agent->external_id,
            'responseMode' => 'sync',
            'conversation' => [
                'externalId' => $conversationExternalId ?? $conversation->external_id,
                'channel' => $this->mapChannelForHub($conversation->connection->channel),
                'contactExternalId' => $conversation->contact->external_id,
                'contactName' => $conversation->contact->name,
            ],
            'message' => array_filter([
                'role' => 'USER',
                'content' => $userMessage,
                'attachments' => $attachments ?: null,
            ], fn ($value) => $value !== null),
        ];

        // A voice note is not input until somebody turns it into words, and the
        // hub only does that when asked. Without this block the file travels
        // and is ignored. Built by the caller (AiTranscription), which is where
        // the flow node's provider choice is known.
        if ($inputAudio !== []) {
            $payload['inputAudio'] = $inputAudio;
        }

        // Asked for here or never: the hub generates the voice as part of the
        // run, so there is no second call that could add it afterwards.
        if ($responseAudio !== []) {
            $payload['responseAudio'] = $responseAudio;
            $metadata['voiceRequested'] = true;
        }

        if (!empty($metadata)) {
            $payload['metadata'] = $metadata;
        }

        $context = [
            'ai_hub_tenant_id' => $tenant->id,
            'hub_agent_id' => $agent->hub_agent_id,
            'conversation_id' => $conversation->id,
        ];

        $carriesExtras = $attachments !== [] || $responseAudio !== [] || $inputAudio !== [];

        // Strip everything optional and keep the words. Shared by both ways a
        // run can fail — an HTTP error, and a 200 carrying a failed run.
        $dropExtras = function () use (&$payload, &$metadata): void {
            unset(
                $payload['message']['attachments'],
                $payload['inputAudio'],
                $payload['responseAudio'],
                $metadata['imageAttachments'],
                $metadata['audioAttachments'],
                $metadata['voiceRequested'],
            );

            $payload['metadata'] = $metadata ?: null;
            $payload = array_filter($payload, fn ($value) => $value !== null);
        };

        try {
            $data = $this->postRun($tenant, $payload, $context);
        } catch (\Throwable $th) {
            if (! $carriesExtras) {
                throw $th;
            }

            // Media and voice are the optional halves of the request: whether
            // the agent's model can accept them at all is decided hub-side, per
            // agent, where we cannot see it — and a hub that has not shipped an
            // audio feature yet rejects the whole run over one unknown field,
            // which is exactly how this app spent an afternoon in August 2026.
            // Losing every reply to a customer who happened to send a
            // screenshot is a far worse failure than answering their text
            // without looking at it, so one retry drops the extras and keeps
            // the conversation alive.
            //
            // A dropped voice note leaves nothing behind — the turn falls back
            // to the "[audio]" placeholder its caller built — but a customer
            // told "could you type that?" is still a customer who was answered,
            // and one written reply beats a silent one.
            Log::warning('AiAgentHubTenantService: run with attachments failed, retrying text-only', array_merge($context, [
                'attachments' => count($attachments),
                'response_audio' => $responseAudio !== [],
                'error' => $th->getMessage(),
            ]));

            $dropExtras();

            $data = $this->postRun($tenant, $payload, $context);
        }

        // A 200 can still carry a run that failed: the hub answers with
        // `status: FAILED`, `output: null` and the stage that threw — an
        // ElevenLabs key without the speech_to_text permission, a voice id
        // that does not exist. Nothing above notices, because nothing threw,
        // and the customer is left in silence, which is the one outcome always
        // worth spending a second request to avoid.
        if (self::runFailed($data)) {
            $error = self::runError($data);

            if (! $carriesExtras) {
                throw new \RuntimeException("AI hub run failed: {$error}");
            }

            Log::warning('AiAgentHubTenantService: the hub failed the run, retrying without audio', array_merge($context, [
                'attachments' => count($attachments),
                'input_audio' => $inputAudio !== [],
                'response_audio' => $responseAudio !== [],
                'error' => $error,
            ]));

            $dropExtras();

            $data = $this->postRun($tenant, $payload, $context);

            if (self::runFailed($data)) {
                // Twice, and the second time with nothing optional left to
                // blame: the caller hands the conversation to a human.
                throw new \RuntimeException('AI hub run failed: ' . self::runError($data));
            }
        }

        $run = $this->persistRun(
            $agent,
            $conversation,
            $userMessage,
            $data,
            $flowStateId,
            $flowNodeId,
            $metadata
        );

        $this->chargeRentedRun($agent, $run);

        return $run;
    }

    /**
     * Bill a completed run to the prepaid wallet, when it ran on a key the
     * platform rents out.
     *
     * After the run, never before: the price is the provider's own cost, and
     * the hub only reports that with the result. A workspace can therefore
     * overdraw by at most one run — see AiCreditService::canSpend().
     *
     * Failures here are swallowed. The reply has already been generated and is
     * on its way to a customer; throwing now would hand the conversation to a
     * human over a bookkeeping problem, and lose the answer that was already
     * paid for at the provider. An unbilled run is a line in the log and a
     * reconcilable gap; a lost reply is not.
     */
    protected function chargeRentedRun(AiHubAgent $agent, AiHubRun $run): void
    {
        $agent->loadMissing('providerCredential');

        if (! $agent->providerCredential?->isRented()) {
            return;
        }

        try {
            app(AiCreditService::class)->chargeRun($run);
        } catch (\Throwable $e) {
            Log::error('AiAgentHubTenantService: failed to charge a rented run to the wallet', [
                'ai_hub_run_id' => $run->id,
                'tenant_id' => $run->tenant_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * True when the hub accepted the request and then could not run it.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function runFailed(array $data): bool
    {
        return strtoupper((string) ($data['status'] ?? '')) === 'FAILED'
            || ($data['error'] ?? null) !== null;
    }

    /** Whatever the hub said went wrong, as one line for a log or an exception. */
    protected static function runError(array $data): string
    {
        $error = $data['error'] ?? null;

        if (is_string($error) && trim($error) !== '') {
            return $error;
        }

        if (is_array($error)) {
            return (string) ($error['message'] ?? json_encode($error));
        }

        return 'status ' . ($data['status'] ?? 'unknown');
    }

    /**
     * POST one run and return the decoded body. Split out of runAgent so the
     * attachment fallback can replay the same request without duplicating the
     * error handling.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function postRun(AiHubTenant $tenant, array $payload, array $context): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/runs", $payload);

        $this->ensureSuccessful($response, 'run agent', $context);

        $data = $response->json() ?? [];

        Log::info('AiAgentHubTenantService: Agent run completed', array_merge($context, [
            'hub_run_id' => $data['id'] ?? null,
            'response' => $data,
        ]));

        return $data;
    }

    /**
     * Map our internal Channel enum to the channel string the hub expects.
     * Both WhatsApp variants collapse to a single "whatsapp" identifier.
     */
    protected function mapChannelForHub(Channel $channel): string
    {
        return match ($channel) {
            Channel::Instagram => 'instagram',
            Channel::Messenger => 'messenger',
            Channel::Discord => 'discord',
            Channel::WhatsappOfficial, Channel::WhatsappApiway => 'whatsapp',
            Channel::Telegram => 'telegram',
            Channel::TikTok => 'tiktok',
            Channel::LiveChatWidget => 'live_chat_widget',
            Channel::Email => throw new \InvalidArgumentException('Email channel not supported for this operation yet'),
        };
    }

    /**
     * Refuse the run when the plan's `max_ai_runs` for the period is spent.
     *
     * Checked here rather than in each caller so nothing can reach the hub
     * around it — this is the only place a billable run is started. Plans
     * without the quota (the majority) pay for one array lookup and no query.
     *
     * Follows the same master switch as every other entitlement check: with
     * BILLING_ENFORCE off, quotas are advisory and nothing is blocked.
     */
    protected function assertWithinRunQuota(AiHubAgent $agent): void
    {
        if (! config('services.mercadopago.enforce')) {
            return;
        }

        $tenant = $agent->aiHubTenant?->tenant;

        if ($tenant === null) {
            return;
        }

        $gate = app(SubscriptionGate::class);
        $limit = $gate->quota($tenant, Quota::MaxAiRuns->value);

        if ($limit === null || $gate->canRunAi($tenant)) {
            return;
        }

        $used = $gate->aiRunsUsed($tenant);

        Log::warning('AiAgentHubTenantService: AI run quota exhausted', [
            'tenant_id' => $tenant->id,
            'ai_hub_agent_id' => $agent->id,
            'limit' => $limit,
            'used' => $used,
        ]);

        throw new AiRunQuotaExceededException($limit, $used);
    }

    /**
     * Refuse the run when it would be spent on a rented platform key with an
     * empty wallet behind it.
     *
     * Only rented keys are gated. A workspace running on its own API key is
     * spending its own money at the provider and owes the platform nothing per
     * run — stopping it here would be inventing a limit nobody sold.
     *
     * Checked in the same place, and behind the same master switch, as the plan
     * quota above: this is the only spot a billable run begins, so there is no
     * caller that can reach the hub around it.
     */
    protected function assertCanSpendCredit(AiHubAgent $agent): void
    {
        if (! config('services.mercadopago.enforce')) {
            return;
        }

        $agent->loadMissing('providerCredential');

        if (! $agent->providerCredential?->isRented()) {
            return;
        }

        $tenant = $agent->aiHubTenant?->tenant;

        if ($tenant === null) {
            return;
        }

        $credits = app(AiCreditService::class);

        if ($credits->canSpend($tenant)) {
            return;
        }

        $balance = $credits->balanceCents($tenant);

        Log::warning('AiAgentHubTenantService: AI credit exhausted on a rented token', [
            'tenant_id' => $tenant->id,
            'ai_hub_agent_id' => $agent->id,
            'balance_cents' => $balance,
        ]);

        throw new AiCreditExhaustedException($balance);
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

        // What the hub heard in the voice notes, returned as a by-product of
        // the run. Kept on the row for two readers: AiTranscripts, which puts
        // it back on the message the agent will read, and anyone later asking
        // why the agent answered a question nobody typed.
        if (is_array($output['inputAudio'] ?? null)) {
            $metadata['inputAudio'] = $output['inputAudio'];
        }

        // The voice file the hub generated, if one was asked for. Kept because
        // the caller has to fetch it before its `expiresAt`, and because a run
        // that was asked to speak and came back mute is only diagnosable from
        // the `status` the hub put here.
        if (is_array($output['audio'] ?? null)) {
            $metadata['responseAudio'] = $output['audio'];
        }

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

    /**
     * Run a call against an agent, and if the hub says it has no such agent,
     * put the agent back and run it once more.
     *
     * The retry is deliberately single and deliberately silent. An agent whose
     * hub copy vanished is not something the person editing it can act on —
     * they know a name and a prompt, not an id — and everything needed to
     * rebuild it is already here. If the second attempt fails too, that is a
     * genuine fault and it surfaces normally.
     */
    protected function healingAgentCall(AiHubAgent $agent, callable $call): Response
    {
        $response = $call();

        if ($response->status() !== 404) {
            return $response;
        }

        Log::warning('AiAgentHubTenantService: the hub lost this agent, rebuilding it', [
            'ai_hub_tenant_id' => $agent->ai_hub_tenant_id,
            'ai_hub_agent_id' => $agent->id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        $this->repushAgent($agent);
        $agent->refresh();

        return $call();
    }

    /* ------------------------------------------------------------------
     | Re-push — rebuilding hub-side objects from the local mirror
     * ------------------------------------------------------------------ */

    /**
     * Make sure a credential still exists at the hub, re-registering it with a
     * placeholder key if not. Cheap enough to do before a repush, and the only
     * way the rebuild can be ordered correctly without the caller knowing it.
     */
    protected function ensureCredentialOnHub(?AiHubProviderCredential $credential): void
    {
        if (! $credential) {
            return;
        }

        // Membership in the list, not GET by id: a read-by-id route we have
        // not proven would answer 404 both when the record is gone and when
        // the route does not exist, and the second case would have us minting
        // a duplicate credential on every single call.
        $rows = $this->listProviderCredentials($credential->aiHubTenant);
        $rows = $rows['data'] ?? $rows;
        $ids = is_array($rows)
            ? array_column(array_filter($rows, 'is_array'), 'id')
            : [];

        if (! in_array($credential->hub_provider_credential_id, $ids, true)) {
            $this->repushProviderCredential($credential);
        }
    }

    /**
     * Re-register a provider credential the hub no longer holds.
     *
     * The provider secret is forwarded to the hub and never kept here, so
     * unless the caller happens to be supplying a new one there is nothing to
     * send but a placeholder. That is deliberate, and it is what lets a
     * workspace be put back together without its owner doing anything: the
     * record, its name, its default model and every agent pointing at it
     * survive, and the only thing left for the customer is the one thing only
     * they can provide — the key itself, entered where they would have entered
     * it anyway.
     *
     * ⚠️ A placeholder credential is ACTIVE and looks ordinary, but no run on
     * it can succeed until the key is replaced — the provider will reject it.
     * `metadata.needs_key` marks that, and it is what the dashboard badges;
     * without the mark the failure would surface as an agent that mysteriously
     * stopped answering.
     */
    public function repushProviderCredential(AiHubProviderCredential $credential, ?string $apiKey = null): AiHubProviderCredential
    {
        $tenant = $credential->aiHubTenant;
        $placeholder = $apiKey === null || $apiKey === '';

        $metadata = $credential->metadata ?? [];
        $metadata['needs_key'] = $placeholder;

        $payload = array_filter([
            'provider' => $credential->provider,
            'name' => $credential->name,
            // Long enough to clear the hub's 8-character minimum, and shaped so
            // that anyone reading the key preview there sees what it is.
            'apiKey' => $placeholder ? 'placeholder-key-' . Str::random(32) : $apiKey,
            'defaultModel' => $credential->default_model,
            'metadata' => $metadata,
        ], fn ($v) => $v !== null);

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/provider-credentials", $payload);

        $this->ensureSuccessful($response, 'repush provider credential', [
            'ai_hub_tenant_id' => $tenant->id,
            'ai_hub_provider_credential_id' => $credential->id,
        ]);

        $data = $response->json() ?? [];

        $credential->update(array_filter([
            'hub_provider_credential_id' => $data['id'] ?? null,
            // The old preview is kept when we sent a placeholder: it is the
            // only remaining hint of *which* key this row used to hold, and the
            // customer needs it to know which one to rotate. Overwriting it
            // with "placeh...aB3x" would throw that away and read like a key.
            'key_preview' => $placeholder ? null : ($data['keyPreview'] ?? null),
            'status' => $data['status'] ?? null,
            'metadata' => $metadata,
        ], fn ($v) => $v !== null));

        return $credential->refresh();
    }

    /**
     * Drop the "waiting for its key" marking once a real one has been stored.
     */
    protected function markCredentialKeyed(AiHubProviderCredential $credential): void
    {
        $metadata = $credential->metadata ?? [];

        if (! ($metadata['needs_key'] ?? false)) {
            return;
        }

        $metadata['needs_key'] = false;
        $credential->update(['metadata' => $metadata]);
    }

    /**
     * Re-create an agent at the hub from what we already hold locally, and
     * repoint the existing row at it.
     *
     * Distinct from {@see createAgent()} on purpose: that one *opens* an agent
     * and inserts a local row for it. This one has the local row already —
     * with its prompt, model and training intact — and only needs the hub to
     * hold a copy again. Creating instead would leave the workspace with two
     * rows for one agent, and orphan the knowledge hanging off the first.
     */
    public function repushAgent(AiHubAgent $agent): AiHubAgent
    {
        $tenant = $agent->aiHubTenant;

        // An agent is created *against* a credential, and the hub refuses one
        // it cannot find. When the hub lost the agent it almost certainly lost
        // the credential too, so check that first — otherwise this fails with
        // "Provider credential not found or disabled", which points at the
        // wrong object entirely.
        $this->ensureCredentialOnHub($agent->providerCredential);
        $agent->load('providerCredential');

        $payload = array_filter([
            'externalId' => $this->buildExternalId(
                $this->normalizeAgentExternalId($agent->external_id, $agent->name),
                $tenant
            ),
            'name' => $agent->name,
            'description' => $agent->description,
            'model' => $agent->model,
            'systemPrompt' => $agent->system_prompt,
            'temperature' => $agent->temperature,
            'maxTokens' => $agent->max_tokens,
            'handoffRules' => $agent->handoff_rules,
            'metadata' => $agent->metadata,
            'providerCredentialId' => $agent->providerCredential?->hub_provider_credential_id,
        ], fn ($v) => $v !== null);

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/agents", $payload);

        $this->ensureSuccessful($response, 'repush agent', [
            'ai_hub_tenant_id' => $tenant->id,
            'ai_hub_agent_id' => $agent->id,
        ]);

        $data = $response->json() ?? [];

        $agent->update([
            'hub_agent_id' => $data['id'] ?? null,
            'external_id' => $data['externalId'] ?? $payload['externalId'],
        ]);

        return $agent->refresh();
    }

    /**
     * Re-push one knowledge item, skill or training example, repointing the
     * local row at the copy the hub now holds. Same reasoning as
     * {@see repushAgent()}: the content is ours, only the id was lost.
     */
    public function repushKnowledge(AiHubKnowledge $knowledge): void
    {
        $agent = $knowledge->aiHubAgent;

        $data = $this->repushChild($agent, 'knowledge', 'repush agent knowledge', array_filter([
            'title' => $knowledge->title,
            'content' => $knowledge->content,
            'tags' => $knowledge->tags,
            'metadata' => $knowledge->metadata,
        ], fn ($v) => $v !== null));

        $knowledge->update(['hub_knowledge_id' => $data['id'] ?? null]);
    }

    public function repushSkill(AiHubSkill $skill): void
    {
        $agent = $skill->aiHubAgent;

        $data = $this->repushChild($agent, 'skills', 'repush agent skill', array_filter([
            'name' => $skill->name,
            'description' => $skill->description,
            'instructions' => $skill->instructions,
            'metadata' => $skill->metadata,
        ], fn ($v) => $v !== null));

        $skill->update(['hub_skill_id' => $data['id'] ?? null]);
    }

    public function repushTrainingExample(AiHubTrainingExample $example): void
    {
        $agent = $example->aiHubAgent;

        $data = $this->repushChild($agent, 'training-examples', 'repush agent training example', array_filter([
            'type' => $example->type,
            'input' => $example->input,
            'expectedOutput' => $example->expected_output,
            'notes' => $example->notes,
            'metadata' => $example->metadata,
        ], fn ($v) => $v !== null));

        $example->update(['hub_example_id' => $data['id'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function repushChild(AiHubAgent $agent, string $segment, string $action, array $payload): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/agents/{$agent->hub_agent_id}/{$segment}", $payload);

        $this->ensureSuccessful($response, $action, [
            'ai_hub_tenant_id' => $agent->ai_hub_tenant_id,
            'hub_agent_id' => $agent->hub_agent_id,
        ]);

        return $response->json() ?? [];
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Wrap a user-supplied external id with the app name *and the workspace*.
     *
     * The workspace half is not decoration. Every workspace's agents now live
     * in one hub scope — the platform's — so two customers who both name an
     * agent "atendimento" would be reaching for the same external id. Under the
     * old one-hub-tenant-per-workspace shape they could not collide; here they
     * would, and the hub answers a collision with a 409 that reads like our bug.
     */
    protected function buildExternalId(string $externalId, AiHubTenant $tenant): string
    {
        $appName = (string) config('app.name');

        return "{$appName}_{$tenant->id}_{$externalId}";
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
     * The platform's own key at the hub.
     *
     * Deliberately *not* per-workspace: Pingly is a single tenant of the hub,
     * so one key authenticates everything we send there. A customer workspace
     * is a local scope — it identifies whose data a call is about, never who
     * is calling. Earlier this read a per-workspace row in `ai_hub_api_keys`,
     * minted through the hub's admin API; that made the platform act as an
     * operator of the hub rather than a tenant of it, and left keys that
     * nothing could rotate once the hub stopped honouring them.
     */
    protected function resolveApiKey(): string
    {
        $token = AiAgentHubConfig::tenantToken();

        if (!$token) {
            throw new Exception(
                'No AI Agent Hub tenant token configured. Set it in Back Office → Integrations → AI Hub.'
            );
        }

        return $token;
    }

    /**
     * Auth headers for every hub call. Adjust here if the hub expects a
     * different header (e.g. `x-hub-api-key`).
     */
    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
    }

    /**
     * Same as {@see ensureSuccessful()}, but a 404 counts as done.
     *
     * A delete asks for a thing to stop existing at the hub, and a hub that
     * never had it has already answered that. Treating the 404 as failure
     * strands the local mirror row: it cannot be updated (404), cannot be
     * deleted (404), and the workspace has no way to clear it — which is
     * exactly what happened when the hub was rebuilt and every id we held
     * stopped resolving.
     */
    protected function ensureDeleted(Response $response, string $action, array $context = []): void
    {
        if ($response->status() === 404) {
            Log::info("AiAgentHubTenantService: nothing to {$action}, the hub does not have it", $context);

            return;
        }

        $this->ensureSuccessful($response, $action, $context);
    }

    /**
     * Whatever the hub said went wrong, as one readable line.
     *
     * The hub's `message` comes back in three shapes and this exists because
     * only one of them used to be handled: `['message'][0]` on a *string* takes
     * its first character, so every plain-text rejection reached the customer as
     * a single letter — "provider must be one of …" arrived as "p". A whole
     * class of validation failure has been unexplainable for as long as that
     * line has been there, in the one place whose entire job is explaining them.
     *
     * The shapes: a string; a list of strings (NestJS class-validator); or an
     * object keyed by field, each holding a list.
     */
    protected static function hubMessage(Response $response, string $fallback): string
    {
        $message = $response->json()['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        if (is_array($message)) {
            $flat = [];

            array_walk_recursive($message, function ($value) use (&$flat) {
                if (is_scalar($value)) {
                    $flat[] = (string) $value;
                }
            });

            if ($flat !== []) {
                return implode(' ', $flat);
            }
        }

        return $fallback;
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

            throw ValidationException::withMessages(['message' => self::hubMessage($response, 'Bad Request')]);
        }elseif($response->status() === 404){
            // Not an error we report — one we repair. See
            // AiHubObjectMissingException and the `repush*` methods.
            Log::warning("AiAgentHubTenantService: the hub does not have what we asked to {$action}", array_merge($context, [
                'status' => $response->status(),
                'body' => $response->body(),
            ]));

            throw new AiHubObjectMissingException("The hub no longer has the object needed to {$action}");
        }elseif($response->status() === 409){
            Log::warning("AiAgentHubTenantService: Conflict occurred trying to {$action}", array_merge($context, [
                'status' => $response->status(),
                'body' => $response->body(),
            ]));

            throw new Exception(self::hubMessage($response, 'Conflict'), 409);
        }

        Log::error("AiAgentHubTenantService: Failed to {$action}", array_merge($context, [
            'status' => $response->status(),
            'body' => $response->body(),
        ]));

        throw new Exception("Failed to {$action}");
    }
}
