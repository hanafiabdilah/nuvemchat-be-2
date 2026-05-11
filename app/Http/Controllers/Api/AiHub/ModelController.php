<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Controller;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    /**
     * List provider models available on the hub. Public on the hub side
     * (no tenant API key required) but still gated by app auth.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->tenantService->listModels(),
        ]);
    }
}
