<?php

namespace App\Http\Controllers\Api\AiHub;

use App\Http\Controllers\Api\AiHub\Concerns\ResolvesAiHubTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiHubTrainingExampleResource;
use App\Models\AiHubAgent;
use App\Models\AiHubTrainingExample;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentTrainingExampleController extends Controller
{
    use ResolvesAiHubTenant;

    public function __construct(protected AiAgentHubTenantService $tenantService)
    {
    }

    public function index(int $agentId): JsonResponse
    {
        $agent = $this->findAgent($agentId);

        $examples = $agent->trainingExamples()
            ->orderBy('created_at', 'DESC')
            ->get();

        return response()->json([
            'data' => AiHubTrainingExampleResource::collection($examples),
        ]);
    }

    public function store(Request $request, int $agentId): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: true);

        $agent = $this->findAgent($agentId);

        $example = $this->tenantService->createAgentTrainingExample($agent, $validated);

        return response()->json([
            'message' => 'Training example created',
            'data' => new AiHubTrainingExampleResource($example),
        ], 201);
    }

    public function update(Request $request, int $agentId, int $exampleId): JsonResponse
    {
        $validated = $this->validatePayload($request, requiredCore: false);

        $example = $this->findExample($agentId, $exampleId);

        $updated = $this->tenantService->updateAgentTrainingExample($example, $validated);

        if (!$updated) {
            return response()->json([
                'message' => 'Training example disabled and removed',
            ]);
        }

        return response()->json([
            'message' => 'Training example updated',
            'data' => new AiHubTrainingExampleResource($updated),
        ]);
    }

    public function destroy(int $agentId, int $exampleId): JsonResponse
    {
        $example = $this->findExample($agentId, $exampleId);

        $this->tenantService->deleteAgentTrainingExample($example);

        return response()->json([
            'message' => 'Training example deleted',
        ]);
    }

    protected function validatePayload(Request $request, bool $requiredCore): array
    {
        $core = $requiredCore ? ['required'] : ['sometimes'];

        return $request->validate([
            'type' => ['sometimes', 'string', 'max:64'],
            'input' => array_merge($core, ['string']),
            'expectedOutput' => array_merge($core, ['string']),
            'notes' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    protected function findAgent(int $id): AiHubAgent
    {
        return $this->aiHubTenant()->agents()->findOrFail($id);
    }

    protected function findExample(int $agentId, int $exampleId): AiHubTrainingExample
    {
        return $this->findAgent($agentId)
            ->trainingExamples()
            ->findOrFail($exampleId);
    }
}
