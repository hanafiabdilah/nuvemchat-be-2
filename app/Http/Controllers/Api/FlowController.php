<?php

namespace App\Http\Controllers\Api;

use App\Enums\Flow\NodeType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlowResource;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FlowController extends Controller
{
    /**
     * Display a listing of flows.
     */
    public function index(): JsonResponse
    {
        $flows = Flow::orderBy('name', 'ASC')->get();

        return response()->json([
            'data' => FlowResource::collection($flows),
        ]);
    }

    /**
     * Display the specified flow.
     */
    public function show(int $id): JsonResponse
    {
        $flow = Flow::with('nodes')->findOrFail($id);

        // Manually load edges for this flow's nodes
        $nodeIds = $flow->nodes->pluck('id');
        $edges = FlowEdge::whereIn('source_node_id', $nodeIds)
            ->orWhereIn('target_node_id', $nodeIds)
            ->get();
        $flow->setRelation('edges', $edges);

        return response()->json([
            'data' => new FlowResource($flow),
        ]);
    }

    /**
     * Store a newly created flow.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $flow = Flow::create($validated);

        $flow->nodes()->create([
            'type' => NodeType::Start,
            'data' => null,
            'position_x' => 0,
            'position_y' => 0,
        ]);

        return response()->json([
            'message' => 'Flow created successfully',
            'data' => new FlowResource($flow),
        ], 201);
    }

    /**
     * Update the specified flow.
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $flow = Flow::findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $flow->update($validated);

        return response()->json([
            'message' => 'Flow updated successfully',
            'data' => new FlowResource($flow->fresh()),
        ]);
    }

    /**
     * Remove the specified flow.
     */
    public function destroy(int $id): JsonResponse
    {
        $flow = Flow::findOrFail($id);

        $flow->delete();

        return response()->json([
            'message' => 'Flow deleted successfully',
        ]);
    }

    /**
     * Save nodes and edges for a flow (auto-save).
     */
    public function saveNodesAndEdges(int $id, Request $request): JsonResponse
    {
        $flow = Flow::findOrFail($id);

        $validated = $request->validate([
            'nodes' => ['required', 'array'],
            'nodes.*.id' => ['nullable', 'string'], // Frontend ID (might not be database ID yet)
            'nodes.*.type' => ['required', 'string', Rule::in(['start', 'message', 'response', 'status', 'tagging', 'condition', 'action'])],
            'nodes.*.data' => ['nullable'],
            'nodes.*.position_x' => ['required', 'numeric'],
            'nodes.*.position_y' => ['required', 'numeric'],
            'edges' => ['required', 'array'],
            'edges.*.source_node_id' => ['required', 'string'], // Frontend node ID
            'edges.*.target_node_id' => ['required', 'string'], // Frontend node ID
        ]);

        // Validate each node's data based on its type
        $this->validateNodesData($validated['nodes']);

        DB::transaction(function () use ($flow, $validated) {
            // Get existing node IDs before deletion
            $existingNodeIds = FlowNode::where('flow_id', $flow->id)->pluck('id');

            // Delete edges associated with these nodes
            if ($existingNodeIds->isNotEmpty()) {
                FlowEdge::whereIn('source_node_id', $existingNodeIds)
                    ->orWhereIn('target_node_id', $existingNodeIds)
                    ->delete();
            }

            // Delete existing nodes
            FlowNode::where('flow_id', $flow->id)->delete();

            // Map frontend node IDs to database IDs
            $nodeIdMap = [];

            // Create nodes
            foreach ($validated['nodes'] as $nodeData) {
                $frontendId = $nodeData['id'] ?? null;

                $node = FlowNode::create([
                    'flow_id' => $flow->id,
                    'type' => $nodeData['type'],
                    'data' => $nodeData['data'] ?? null,
                    'position_x' => $nodeData['position_x'],
                    'position_y' => $nodeData['position_y'],
                ]);

                if ($frontendId) {
                    $nodeIdMap[$frontendId] = $node->id;
                }
            }

            // Create edges with mapped node IDs
            foreach ($validated['edges'] as $edgeData) {
                $sourceId = $nodeIdMap[$edgeData['source_node_id']] ?? null;
                $targetId = $nodeIdMap[$edgeData['target_node_id']] ?? null;

                if ($sourceId && $targetId) {
                    FlowEdge::create([
                        'source_node_id' => $sourceId,
                        'target_node_id' => $targetId,
                    ]);
                }
            }
        });

        // Load nodes and manually get edges for this flow's nodes
        $flow->load('nodes');
        $nodeIds = $flow->nodes->pluck('id');
        $edges = FlowEdge::whereIn('source_node_id', $nodeIds)
            ->orWhereIn('target_node_id', $nodeIds)
            ->get();
        $flow->setRelation('edges', $edges);

        return response()->json([
            'message' => 'Flow saved successfully',
            'data' => new FlowResource($flow),
        ]);
    }

    /**
     * Validate nodes data based on their type.
     */
    private function validateNodesData(array $nodes): void
    {
        foreach ($nodes as $index => $node) {
            $type = $node['type'];
            $data = $node['data'] ?? null;

            if ($data === null) {
                if ($type === 'start') continue;

                throw ValidationException::withMessages([
                    "nodes.{$index}.data" => ["The data field is required for node type {$type}."]
                ]);
            }

            $rules = $this->getValidationRulesForNodeType($type);

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["nodes.{$index}.data.{$field}"] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
        }
    }

    /**
     * Get validation rules for node data based on type.
     */
    private function getValidationRulesForNodeType(string $type): array
    {
        return match ($type) {
            'message' => [
                'body' => ['required', 'string'],
                'message_type' => ['required', 'string', Rule::in(['text', 'image', 'audio', 'video', 'document'])],
                'attachment' => ['nullable', 'string'],
                'delay' => ['nullable', 'integer', 'min:0'],
            ],
            'response' => [
                'body' => ['required', 'string'],
                'message_type' => ['required', 'string', Rule::in(['text', 'image', 'audio', 'video', 'document'])],
                'attachment' => ['nullable', 'string'],
                'variable_key' => ['required', 'string'],
                'validation' => ['nullable', 'string', Rule::in(['any', 'number', 'email', 'phone'])],
                'error_message' => ['nullable', 'string'],
            ],
            'status' => [
                'value' => ['required', 'string', Rule::in(['open', 'pending', 'resolved'])],
            ],
            'tagging' => [
                'tags' => ['required', 'array'],
                'tags.*' => ['integer', 'exists:tags,id'],
            ],
            'condition' => [
                'field' => ['required', 'string'],
                'operator' => ['required', 'string', Rule::in(['equals', 'not_equals', 'contains', 'not_contains'])],
                'value' => ['required', 'string'],
            ],
            'action' => [
                'type' => ['required', 'string'],
                'parameters' => ['required', 'array'],
            ],
            default => [],
        };
    }
}
