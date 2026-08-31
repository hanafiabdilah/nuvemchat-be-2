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
        ]);

        $credential = $this->findCredential($id);

        // A rented credential is the platform's key, held in the tenant's hub
        // scope. Letting this through would let a workspace overwrite the
        // secret behind a key other workspaces are sharing, or disable it for
        // everyone — through an endpoint that looks like it edits their own row.
        $this->refuseIfRented($credential);

        $updated = $this->tenantService->updateProviderCredential($credential, $validated);

        return response()->json([
            'message' => 'Provider credential updated',
            'data' => new AiHubProviderCredentialResource($updated),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $credential = $this->findCredential($id);

        // Giving a rented key back is a different operation with its own
        // checks (nothing may still point at it) and its own endpoint. Sending
        // the tenant there by name beats a generic 403.
        $this->refuseIfRented($credential, 'Use the rental endpoint to give a rented token back.');

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

    /**
     * Rented credentials are read-only to the workspace holding them.
     */
    protected function refuseIfRented(AiHubProviderCredential $credential, ?string $message = null): void
    {
        abort_if(
            $credential->isRented(),
            422,
            $message ?? 'This token is rented from the platform and cannot be edited here.'
        );
    }
}
