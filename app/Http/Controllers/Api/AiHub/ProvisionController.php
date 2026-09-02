<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubTenantResource;
use Illuminate\Http\JsonResponse;

class ProvisionController extends Controller
{
    use ResolvesAiHubTenant;

    /**
     * Open the current workspace's local AI-hub scope (idempotent). Nothing is
     * registered on the hub: the platform is the hub's tenant, not the
     * workspace. `has_active_api_key` in the response reports whether the
     * platform's own hub token is configured.
     */
    public function store(): JsonResponse
    {
        $aiHubTenant = $this->aiHubTenant();

        return response()->json([
            'message' => 'AI Agent Hub tenant provisioned',
            'data' => new AiHubTenantResource($aiHubTenant),
        ]);
    }
}
