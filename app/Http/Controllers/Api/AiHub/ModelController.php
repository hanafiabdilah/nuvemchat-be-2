<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiTokens\AiModelCatalog;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(
        protected AiAgentHubTenantService $tenantService,
        protected AiModelCatalog $catalog,
    ) {
    }

    /**
     * Models a tenant can name when building an agent.
     *
     * Our own catalogue first, with whatever the hub reports merged on top —
     * the same rule the Back Office follows, and for the same reason: the
     * hub's list is incomplete and sometimes empty, so building the dropdown
     * out of it alone left the agent form offering nothing while the platform
     * knew perfectly well what the provider runs.
     *
     * The shape is unchanged (`{provider, id, name}` per row), so the pickers
     * that read it did not have to learn anything new.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (array $model) => [
                    'provider' => $model['provider'],
                    'id' => $model['id'],
                    'name' => $model['name'],
                ],
                $this->catalog->catalog()['models'],
            ),
        ]);
    }
}
