<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubProviderCredentialResource;
use App\Models\AiHubProviderCredential;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderCredentialController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    public function index(): JsonResponse
    {
        $aiHubTenant = $this->aiHubTenant();

        $credentials = $aiHubTenant->providerCredentials()
            ->orderBy('created_at', 'DESC')
            ->get();

        return response()->json([
            'data' => AiHubProviderCredentialResource::collection($credentials),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'apiKey' => ['required', 'string'],
            'defaultModel' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        $aiHubTenant = $this->aiHubTenant();

        $credential = $this->tenantService->createProviderCredential($aiHubTenant, $validated);

        return response()->json([
            'message' => 'Provider credential created',
            'data' => new AiHubProviderCredentialResource($credential),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'apiKey' => ['sometimes', 'string'],
            'defaultModel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,DISABLED'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $credential = $this->findCredential($id);

        $updated = $this->tenantService->updateProviderCredential($credential, $validated);

        return response()->json([
            'message' => 'Provider credential updated',
            'data' => new AiHubProviderCredentialResource($updated),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $credential = $this->findCredential($id);

        $this->tenantService->deleteProviderCredential($credential);

        return response()->json([
            'message' => 'Provider credential deleted',
        ]);
    }

    /**
     * Find a credential scoped to the current user's AiHubTenant.
     */
    protected function findCredential(int $id): AiHubProviderCredential
    {
        $aiHubTenant = $this->aiHubTenant();

        return $aiHubTenant->providerCredentials()->findOrFail($id);
    }
}
