<?php

namespace App\Http\Controllers\Api;

use App\Enums\Flow\NodeType;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlowResource;
use App\Http\Resources\FlowSummaryResource;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\InteractiveNodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FlowController extends Controller
{
    /**
     * The file contract lives in FlowBlueprint, which the AI assistant also
     * validates against. Two copies of these rules would be identical on the
     * day they were written and silently divergent after the next node type —
     * with the divergence surfacing as a generated flow the save endpoint
     * refuses, which is the one failure the assistant exists to prevent.
     */
    private const NODE_TYPES = FlowBlueprint::NODE_TYPES;
    private const BRANCH_VALUE_PATTERN = FlowBlueprint::BRANCH_VALUE_PATTERN;
    private const EXPORT_FORMAT = FlowBlueprint::EXPORT_FORMAT;
    private const EXPORT_VERSION = FlowBlueprint::EXPORT_VERSION;

    /**
     * Display a listing of flows.
     */
    public function index(): JsonResponse
    {
        // Shape, not content. The list draws a thumbnail of each flow, which
        // needs the node types, where they sit and what joins them — and
        // nothing else. `data` is the whole builder payload (message bodies, AI
        // prompts, HTTP headers) and is deliberately excluded from the select:
        // a screen showing twenty flows must not ship twenty flows.
        $flows = Flow::where('tenant_id', auth()->user()->tenant_id)
            ->withCount('nodes')
            ->with([
                'nodes' => fn ($query) => $query->select(['id', 'flow_id', 'type', 'position_x', 'position_y']),
                'edges',
            ])
            ->orderBy('name', 'ASC')
            ->get();

        return response()->json([
            'data' => FlowSummaryResource::collection($flows),
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
            'edges.*.condition_value' => ['nullable', 'string', self::BRANCH_VALUE_PATTERN],
        ]);

        // Validate each node's data based on its type
        $this->validateNodesData($validated['nodes']);

        // Interactive nodes only run on WhatsApp Official, so a flow that uses
        // one cannot stay wired to any other channel.
        $this->assertInteractiveNodesAllowed($flow, $validated['nodes']);

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
            'flow.edges.*.condition_value' => ['nullable', 'string', self::BRANCH_VALUE_PATTERN],
        ], [
            'format.in' => 'This file is not a valid Nuvemchat flow export.',
            'version.max' => 'This flow export was created by a newer version and cannot be imported.',
        ]);

        $nodes = $validated['flow']['nodes'];
        $edges = $validated['flow']['edges'];

        // Per-type data validation — same rules as saving a flow.
        $this->validateNodesData($nodes);

        // Structural integrity: unique keys, exactly one start, edges resolve.
        FlowBlueprint::assertStructure($nodes, $edges);

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
     * Refuse a flow that mixes interactive nodes with connections that cannot
     * run them. Reply buttons and list menus exist only on the WhatsApp Cloud
     * API, so the moment a flow uses one it is a WhatsApp-Official-only flow.
     * The mirror check lives in ConnectionController, which refuses to point a
     * non-official connection at such a flow.
     */
    private function assertInteractiveNodesAllowed(Flow $flow, array $nodes): void
    {
        if (!InteractiveNodes::payloadUsesInteractive($nodes)) {
            return;
        }

        $conflicting = InteractiveNodes::conflictingConnections($flow);

        if ($conflicting->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'nodes' => [
                'This flow uses WhatsApp buttons or a list menu, which only work on WhatsApp Official. '
                . 'Unlink it from these connections first: ' . InteractiveNodes::describeConnections($conflicting) . '.',
            ],
        ]);
    }

    /**
     * Validate nodes data based on their type.
     */
    private function validateNodesData(array $nodes): void
    {
        FlowBlueprint::validateNodes($nodes);
    }
}
