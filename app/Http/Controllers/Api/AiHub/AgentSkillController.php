<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubSkillResource;
use App\Models\AiHubAgent;
use App\Models\AiHubSkill;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentSkillController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    public function index(int $agentId): JsonResponse
    {
        $agent = $this->findAgent($agentId);

        $skills = $agent->skills()
            ->orderBy('created_at', 'DESC')
            ->get();

        return response()->json([
            'data' => AiHubSkillResource::collection($skills),
        ]);
    }

    public function store(Request $request, int $agentId): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: true);

        $agent = $this->findAgent($agentId);

        $skill = $this->tenantService->createAgentSkill($agent, $validated);

        return response()->json([
            'message' => 'Skill created',
            'data' => new AiHubSkillResource($skill),
        ], 201);
    }

    public function update(Request $request, int $agentId, int $skillId): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: false);

        $skill = $this->findSkill($agentId, $skillId);

        $updated = $this->tenantService->updateAgentSkill($skill, $validated);

        if (!$updated) {
            return response()->json([
                'message' => 'Skill disabled and removed',
            ]);
        }

        return response()->json([
            'message' => 'Skill updated',
            'data' => new AiHubSkillResource($updated),
        ]);
    }

    public function destroy(int $agentId, int $skillId): JsonResponse
    {
        $skill = $this->findSkill($agentId, $skillId);

        $this->tenantService->deleteAgentSkill($skill);

        return response()->json([
            'message' => 'Skill deleted',
        ]);
    }

    protected function validatePayload(Request $request, bool $requiredCore): array
    {
        $core = $requiredCore ? ['required'] : ['sometimes'];

        return $request->validate([
            'name' => array_merge($core, ['string', 'max:255']),
            'description' => ['sometimes', 'nullable', 'string'],
            'instructions' => ['sometimes', 'nullable', 'array'],
            'instructions.*' => ['string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    protected function findAgent(int $id): AiHubAgent
    {
        return $this->aiHubTenant()->agents()->findOrFail($id);
    }

    protected function findSkill(int $agentId, int $skillId): AiHubSkill
    {
        return $this->findAgent($agentId)
            ->skills()
            ->findOrFail($skillId);
    }
}
