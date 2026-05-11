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
     * Provision the current user's tenant on the AI Agent Hub (idempotent).
     * Creates the hub tenant + an active API key if missing.
     */
    public function store(): JsonResponse
    {
        $aiHubTenant = $this->aiHubTenant()->load('activeApiKey');

        return response()->json([
            'message' => 'AI Agent Hub tenant provisioned',
            'data' => new AiHubTenantResource($aiHubTenant),
        ]);
    }
}
