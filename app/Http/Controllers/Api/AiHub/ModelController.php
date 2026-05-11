<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    /**
     * List provider models available on the hub for the current tenant.
     */
    public function index(): JsonResponse
    {
        $aiHubTenant = $this->aiHubTenant();

        return response()->json([
            'data' => $this->tenantService->listModels($aiHubTenant),
        ]);
    }
}
