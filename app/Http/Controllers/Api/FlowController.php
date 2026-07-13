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
    /** Allowed node types (frontend must stay in sync). */
    private const NODE_TYPES = ['start', 'message', 'response', 'status', 'tagging', 'condition', 'action', 'ai_agent', 'http_request'];

    /** Allowed edge branch values: condition (true/false) + http_request (success/error). */
    private const BRANCH_VALUES = ['true', 'false', 'success', 'error'];

    /** Portable export envelope identifiers. */
    private const EXPORT_FORMAT = 'nuvemchat.flow';
    private const EXPORT_VERSION = 1;

    /**
     * Display a listing of flows.
     */
    public function index(): JsonResponse
    {
        $flows = Flow::where('tenant_id', auth()->user()->tenant_id)->orderBy('name', 'ASC')->get();

        return response()->json([
            'data' => FlowResource::collection($flows),
        ]);
    }

    /**
     * Display the specified flow.
     */
    public function show(int $id): JsonResponse
    {
        $flow = Flow::with('nodes')->where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);

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

        $validated['tenant_id'] = auth()->user()->tenant_id;
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
        $flow = Flow::where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);

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
        $flow = Flow::where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);

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
        $flow = Flow::where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'nodes' => ['required', 'array'],
            'nodes.*.id' => ['nullable', 'string'], // Frontend ID (might not be database ID yet)
            'nodes.*.type' => ['required', 'string', Rule::in(self::NODE_TYPES)],
            'nodes.*.data' => ['nullable'],
            'nodes.*.position_x' => ['required', 'numeric'],
            'nodes.*.position_y' => ['required', 'numeric'],
            'edges' => ['required', 'array'],
            'edges.*.source_node_id' => ['required', 'string'], // Frontend node ID
            'edges.*.target_node_id' => ['required', 'string'], // Frontend node ID
            'edges.*.condition_value' => ['nullable', 'string', Rule::in(self::BRANCH_VALUES)], // condition (true/false) & http_request (success/error) branches
        ]);

        // Validate each node's data based on its type
        $this->validateNodesData($validated['nodes']);

        DB::transaction(function () use ($flow, $validated) {
            // Get all existing nodes for this flow
            $existingNodes = FlowNode::where('flow_id', $flow->id)->get()->keyBy('id');

            // Track which nodes are in the new request (by database ID)
            $requestedNodeIds = [];

            // Map frontend node IDs to database IDs
            $nodeIdMap = [];

            // Update or create nodes
            foreach ($validated['nodes'] as $nodeData) {
                $frontendId = $nodeData['id'] ?? null;

                // Check if this is an existing node (numeric ID) or new node (UUID/string)
                $isExistingNode = $frontendId && is_numeric($frontendId) && $existingNodes->has((int)$frontendId);

                if ($isExistingNode) {
                    // UPDATE existing node (preserve ID)
                    $nodeId = (int)$frontendId;
                    $existingNodes->get($nodeId)->update([
                        'type' => $nodeData['type'],
                        'data' => $nodeData['data'] ?? null,
                        'position_x' => $nodeData['position_x'],
                        'position_y' => $nodeData['position_y'],
                    ]);

                    $requestedNodeIds[] = $nodeId;
                    $nodeIdMap[$frontendId] = $nodeId;
                } else {
                    // CREATE new node
                    $node = FlowNode::create([
                        'flow_id' => $flow->id,
                        'type' => $nodeData['type'],
                        'data' => $nodeData['data'] ?? null,
                        'position_x' => $nodeData['position_x'],
                        'position_y' => $nodeData['position_y'],
                    ]);

                    $requestedNodeIds[] = $node->id;
                    if ($frontendId) {
                        $nodeIdMap[$frontendId] = $node->id;
                    }
                }
            }

            // Delete nodes that are no longer in the request
            $nodesToDelete = $existingNodes->keys()->diff($requestedNodeIds);
            if ($nodesToDelete->isNotEmpty()) {
                // Delete edges associated with deleted nodes
                FlowEdge::whereIn('source_node_id', $nodesToDelete)
                    ->orWhereIn('target_node_id', $nodesToDelete)
                    ->delete();

                // Delete the nodes
                FlowNode::whereIn('id', $nodesToDelete)->delete();
            }

            // Recreate all edges (simpler than diffing)
            // First, delete all edges for remaining nodes
            if (!empty($requestedNodeIds)) {
                FlowEdge::whereIn('source_node_id', $requestedNodeIds)
                    ->orWhereIn('target_node_id', $requestedNodeIds)
                    ->delete();
            }

            // Create edges with mapped node IDs
            foreach ($validated['edges'] as $edgeData) {
                $sourceId = $nodeIdMap[$edgeData['source_node_id']] ?? null;
                $targetId = $nodeIdMap[$edgeData['target_node_id']] ?? null;

                if ($sourceId && $targetId) {
                    FlowEdge::create([
                        'source_node_id' => $sourceId,
                        'target_node_id' => $targetId,
                        'condition_value' => $edgeData['condition_value'] ?? null,
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

        $flow->update(['last_updated_at' => now()]);

        return response()->json([
            'message' => 'Flow saved successfully',
            'data' => new FlowResource($flow),
        ]);
    }

    /**
     * Export a flow as a portable, self-contained JSON envelope. Node database
     * ids are replaced with local "key" strings and edges reference those keys,
     * so the flow re-imports cleanly with fresh ids.
     */
    public function export(int $id): JsonResponse
    {
        $flow = Flow::with('nodes')->where('tenant_id', auth()->user()->tenant_id)->findOrFail($id);

        $nodeIds = $flow->nodes->pluck('id');
        $edges = FlowEdge::whereIn('source_node_id', $nodeIds)
            ->whereIn('target_node_id', $nodeIds)
            ->get();

        return response()->json([
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'flow' => [
                'name' => $flow->name,
                'nodes' => $flow->nodes->map(fn (FlowNode $node) => [
                    'key' => (string) $node->id,
                    'type' => $node->type->value,
                    'data' => $node->data,
                    'position_x' => $node->position_x,
                    'position_y' => $node->position_y,
                ])->values(),
                'edges' => $edges->map(fn (FlowEdge $edge) => [
                    'source_key' => (string) $edge->source_node_id,
                    'target_key' => (string) $edge->target_node_id,
                    'condition_value' => $edge->condition_value,
                ])->values(),
            ],
        ]);
    }

    /**
     * Import a flow from an export envelope, creating a brand-new flow (with
     * fresh ids) for the current tenant. Node data is validated with the same
     * per-type rules as saving, so tenant-specific references (tags, AI agents)
     * must resolve for this tenant.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'string', Rule::in([self::EXPORT_FORMAT])],
            'version' => ['required', 'integer', 'max:' . self::EXPORT_VERSION],
            'name' => ['nullable', 'string', 'max:255'], // optional name override
            'flow' => ['required', 'array'],
            'flow.name' => ['required', 'string', 'max:255'],
            'flow.nodes' => ['required', 'array', 'min:1'],
            'flow.nodes.*.key' => ['required', 'string'],
            'flow.nodes.*.type' => ['required', 'string', Rule::in(self::NODE_TYPES)],
            'flow.nodes.*.data' => ['nullable'],
            'flow.nodes.*.position_x' => ['required', 'numeric'],
            'flow.nodes.*.position_y' => ['required', 'numeric'],
            'flow.edges' => ['present', 'array'],
            'flow.edges.*.source_key' => ['required', 'string'],
            'flow.edges.*.target_key' => ['required', 'string'],
            'flow.edges.*.condition_value' => ['nullable', 'string', Rule::in(self::BRANCH_VALUES)],
        ], [
            'format.in' => 'This file is not a valid Nuvemchat flow export.',
            'version.max' => 'This flow export was created by a newer version and cannot be imported.',
        ]);

        $nodes = $validated['flow']['nodes'];
        $edges = $validated['flow']['edges'];

        // Per-type data validation — same rules as saving a flow.
        $this->validateNodesData($nodes);

        // Structural integrity: unique keys, exactly one start, edges resolve.
        $keys = array_map(fn ($node) => $node['key'], $nodes);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['flow.nodes' => ['Duplicate node keys in the flow export.']]);
        }

        if (collect($nodes)->where('type', 'start')->count() !== 1) {
            throw ValidationException::withMessages(['flow.nodes' => ['A flow must contain exactly one start node.']]);
        }

        $keySet = array_flip($keys);
        foreach ($edges as $edge) {
            if (!isset($keySet[$edge['source_key']]) || !isset($keySet[$edge['target_key']])) {
                throw ValidationException::withMessages(['flow.edges' => ['An edge references a node that is not in the flow.']]);
            }
        }

        $name = $validated['name'] ?? $validated['flow']['name'];

        $flow = DB::transaction(function () use ($nodes, $edges, $name) {
            $flow = Flow::create([
                'tenant_id' => auth()->user()->tenant_id,
                'name' => $name,
            ]);

            // Map export keys → freshly created node ids.
            $keyToId = [];
            foreach ($nodes as $node) {
                $created = $flow->nodes()->create([
                    'type' => $node['type'],
                    'data' => $node['data'] ?? null,
                    'position_x' => $node['position_x'],
                    'position_y' => $node['position_y'],
                ]);
                $keyToId[$node['key']] = $created->id;
            }

            foreach ($edges as $edge) {
                FlowEdge::create([
                    'source_node_id' => $keyToId[$edge['source_key']],
                    'target_node_id' => $keyToId[$edge['target_key']],
                    'condition_value' => $edge['condition_value'] ?? null,
                ]);
            }

            $flow->update(['last_updated_at' => now()]);

            return $flow;
        });

        $flow->load('nodes');
        $newNodeIds = $flow->nodes->pluck('id');
        $newEdges = FlowEdge::whereIn('source_node_id', $newNodeIds)
            ->whereIn('target_node_id', $newNodeIds)
            ->get();
        $flow->setRelation('edges', $newEdges);

        return response()->json([
            'message' => 'Flow imported successfully',
            'data' => new FlowResource($flow),
        ], 201);
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
                'attachment_url' => ['nullable', 'string'],
                'delay' => ['nullable', 'integer', 'min:0'],
                'wait_for_reply' => ['nullable', 'boolean'],
            ],
            'response' => [
                'body' => ['required', 'string'],
                'message_type' => ['required', 'string', Rule::in(['text', 'image', 'audio', 'video', 'document'])],
                'attachment_url' => ['nullable', 'string'],
                'variable_key' => ['required', 'string'],
                'validation' => ['nullable', 'string', Rule::in(['any', 'number', 'email', 'phone'])],
                'error_message' => ['nullable', 'string'],
            ],
            'status' => [
                'value' => ['required', 'string', Rule::in(['open', 'pending', 'resolved'])],
            ],
            'tagging' => [
                'action' => ['required', 'string', Rule::in(['add', 'remove'])],
                'tags' => ['nullable', 'array'],
                'tags.*' => ['integer', 'exists:tags,id'],
            ],
            'condition' => [
                'field' => ['required', 'string'],
                'operator' => ['required', 'string', Rule::in(['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'])],
                'value' => ['nullable', 'string'], // nullable for is_empty/is_not_empty operators
            ],
            'action' => [
                'type' => ['required', 'string'],
                'parameters' => ['required', 'array'],
            ],
            'ai_agent' => [
                'ai_hub_agent_id' => [
                    'required',
                    'integer',
                    Rule::exists('ai_hub_agents', 'id')->where(function ($query) {
                        $tenantId = auth()->user()->tenant_id;
                        $query->whereIn('ai_hub_tenant_id', function ($sub) use ($tenantId) {
                            $sub->select('id')
                                ->from('ai_hub_tenants')
                                ->where('tenant_id', $tenantId);
                        })->where('status', 'ACTIVE');
                    }),
                ],
                'welcoming_message' => ['required', 'string', 'max:4000'],
                'store_summary_to_variable' => ['nullable', 'string', 'alpha_dash'],
            ],
            'http_request' => [
                'method' => ['required', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
                // nullable so an in-progress node doesn't break auto-save; the
                // executor takes the error branch when the URL is empty at runtime.
                'url' => ['nullable', 'string', 'max:2000'],
                'headers' => ['nullable', 'array'],
                'headers.*.key' => ['nullable', 'string', 'max:255'],
                'headers.*.value' => ['nullable', 'string', 'max:2000'],
                'body' => ['nullable', 'string'],
                'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
                'response_mappings' => ['nullable', 'array'],
                'response_mappings.*.path' => ['nullable', 'string', 'max:255'],
                'response_mappings.*.variable' => ['nullable', 'string', 'max:255'],
            ],
            default => [],
        };
    }
}
