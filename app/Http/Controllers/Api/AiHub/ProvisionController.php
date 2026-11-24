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
     * Open the current workspace's local AI-hub scope (idempotent), and — with
     * per-workspace accounts on — its account inside our hub tenant. The
     * platform stays the hub's only tenant and keeps its one token.
     * `has_active_api_key` in the response reports whether that token is
     * configured.
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
