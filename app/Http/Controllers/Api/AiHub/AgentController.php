<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubAgentResource;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    public function index(): JsonResponse
    {
        $aiHubTenant = $this->aiHubTenant();

        $agents = $aiHubTenant->agents()
            ->with('providerCredential')
            ->orderBy('created_at', 'DESC')
            ->get();

        return response()->json([
            'data' => AiHubAgentResource::collection($agents),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: true);

        $aiHubTenant = $this->aiHubTenant();

        $payload = $this->resolveProviderCredentialId($validated, $aiHubTenant->id);

        $agent = $this->tenantService->createAgent($aiHubTenant, $payload);
        $agent->load('providerCredential');

        return response()->json([
            'message' => 'Agent created',
            'data' => new AiHubAgentResource($agent),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: false);

        $agent = $this->findAgent($id);

        $payload = $this->resolveProviderCredentialId($validated, $agent->ai_hub_tenant_id);

        $updated = $this->tenantService->updateAgent($agent, $payload);
        $updated->load('providerCredential');

        return response()->json([
            'message' => 'Agent updated',
            'data' => new AiHubAgentResource($updated),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $agent = $this->findAgent($id);

        $this->tenantService->deleteAgent($agent);

        return response()->json([
            'message' => 'Agent deleted',
        ]);
    }

    /**
     * Validation rules shared by store & update. `$requiredCore` controls
     * whether the core fields (name, providerCredentialId, model) are
     * required (POST) or optional (PATCH).
     */
    protected function validatePayload(Request $request, bool $requiredCore): array
    {
        $core = $requiredCore ? ['required'] : ['sometimes'];

        return $request->validate([
            'externalId' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => array_merge($core, ['string', 'max:255']),
            'description' => ['sometimes', 'nullable', 'string'],
            'providerCredentialId' => array_merge($core, ['integer', 'exists:ai_hub_provider_credentials,id']),
            'model' => array_merge($core, ['string', 'max:100']),
            'systemPrompt' => ['sometimes', 'nullable', 'string'],
            'temperature' => ['sometimes', 'nullable', 'numeric', 'between:0,2'],
            'maxTokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,DISABLED'],
            'handoffRules' => ['sometimes', 'nullable', 'array'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    /**
     * Translate `providerCredentialId` from the local DB id to the hub id
     * the hub expects in the payload. Scoped to the given AiHubTenant.
     */
    protected function resolveProviderCredentialId(array $payload, int $aiHubTenantId): array
    {
        if (!isset($payload['providerCredentialId'])) {
            return $payload;
        }

        $hubId = AiHubProviderCredential::query()
            ->where('ai_hub_tenant_id', $aiHubTenantId)
            ->where('id', $payload['providerCredentialId'])
            ->value('hub_provider_credential_id');

        abort_unless($hubId, 422, 'providerCredentialId does not belong to this tenant');

        $payload['providerCredentialId'] = $hubId;

        return $payload;
    }

    /**
     * Find an agent scoped to the current user's AiHubTenant.
     */
    protected function findAgent(int $id): AiHubAgent
    {
        $aiHubTenant = $this->aiHubTenant();

        return $aiHubTenant->agents()->findOrFail($id);
    }
}
