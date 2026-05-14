<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubAgentProfileResource;
use App\Models\AiHubAgent;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentProfileController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    public function show(int $agentId): JsonResponse
    {
        $agent = $this->findAgent($agentId);
        $agent->load('profile');

        return response()->json([
            'data' => $agent->profile
                ? new AiHubAgentProfileResource($agent->profile)
                : null,
        ]);
    }

    public function update(Request $request, int $agentId): JsonResponse
    {
        $validated = $request->validate([
            'language' => ['nullable', 'string', 'max:32'],
            'tone' => ['nullable', 'string', 'max:500'],
            'responseStyle' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'array'],
            'instructions.*' => ['string'],
            'limits' => ['nullable', 'array'],
            'limits.*' => ['string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $agent = $this->findAgent($agentId);

        $profile = $this->tenantService->setAgentProfile($agent, $validated);

        return response()->json([
            'message' => 'Agent profile saved',
            'data' => new AiHubAgentProfileResource($profile),
        ]);
    }

    protected function findAgent(int $id): AiHubAgent
    {
        $aiHubTenant = $this->aiHubTenant();

        return $aiHubTenant->agents()->findOrFail($id);
    }
}
