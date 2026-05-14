<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubKnowledgeResource;
use App\Models\AiHubAgent;
use App\Models\AiHubKnowledge;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentKnowledgeController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    public function index(int $agentId): JsonResponse
    {
        $agent = $this->findAgent($agentId);

        $knowledge = $agent->knowledge()
            ->orderBy('created_at', 'DESC')
            ->get();

        return response()->json([
            'data' => AiHubKnowledgeResource::collection($knowledge),
        ]);
    }

    public function store(Request $request, int $agentId): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: true);

        $agent = $this->findAgent($agentId);

        $knowledge = $this->tenantService->createAgentKnowledge($agent, $validated);

        return response()->json([
            'message' => 'Knowledge created',
            'data' => new AiHubKnowledgeResource($knowledge),
        ], 201);
    }

    public function update(Request $request, int $agentId, int $knowledgeId): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: false);

        $knowledge = $this->findKnowledge($agentId, $knowledgeId);

        $updated = $this->tenantService->updateAgentKnowledge($knowledge, $validated);

        if (!$updated) {
            return response()->json([
                'message' => 'Knowledge disabled and removed',
            ]);
        }

        return response()->json([
            'message' => 'Knowledge updated',
            'data' => new AiHubKnowledgeResource($updated),
        ]);
    }

    public function destroy(int $agentId, int $knowledgeId): JsonResponse
    {
        $knowledge = $this->findKnowledge($agentId, $knowledgeId);

        $this->tenantService->deleteAgentKnowledge($knowledge);

        return response()->json([
            'message' => 'Knowledge deleted',
        ]);
    }

    protected function validatePayload(Request $request, bool $requiredCore): array
    {
        $core = $requiredCore ? ['required'] : ['sometimes'];

        return $request->validate([
            'title' => array_merge($core, ['string', 'max:255']),
            'content' => array_merge($core, ['string']),
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    protected function findAgent(int $id): AiHubAgent
    {
        return $this->aiHubTenant()->agents()->findOrFail($id);
    }

    protected function findKnowledge(int $agentId, int $knowledgeId): AiHubKnowledge
    {
        return $this->findAgent($agentId)
            ->knowledge()
            ->findOrFail($knowledgeId);
    }
}
