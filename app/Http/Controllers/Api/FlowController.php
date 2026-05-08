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
use Illuminate\Support\Facades\Storage;
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
            'nodes.*.type' => ['required', 'string', Rule::in(['start', 'message', 'response', 'status', 'tagging', 'condition', 'action'])],
            'nodes.*.data' => ['nullable'],
            'nodes.*.position_x' => ['required', 'numeric'],
            'nodes.*.position_y' => ['required', 'numeric'],
            'edges' => ['required', 'array'],
            'edges.*.source_node_id' => ['required', 'string'], // Frontend node ID
            'edges.*.target_node_id' => ['required', 'string'], // Frontend node ID
            'edges.*.condition_value' => ['nullable', 'string', Rule::in(['true', 'false'])], // For condition nodes
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

            // Handle file upload for attachment
            $this->handleAttachmentUpload($node, $index);
        }
    }

    /**
     * Handle attachment file upload for message and response nodes
     */
    private function handleAttachmentUpload(array &$node, int $index): void
    {
        $type = $node['type'];

        if (!in_array($type, ['message', 'response'])) {
            return;
        }

        $data = &$node['data'];

        // Check if there's an attachment file in the request
        $fileKey = "nodes.{$index}.data.attachment_file";

        if (request()->hasFile($fileKey)) {
            $file = request()->file($fileKey);
            $messageType = $data['message_type'] ?? 'text';

            // Validate file based on message type
            $validationRules = $this->getAttachmentValidationRules($messageType);

            $validator = Validator::make(
                ['attachment_file' => $file],
                ['attachment_file' => $validationRules]
            );

            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors[$fileKey] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }

            // Store file in flow_attachments directory
            $extension = $file->getClientOriginalExtension();
            $fileName = 'flow_' . uniqid() . '.' . $extension;
            $path = $file->storeAs('flow_attachments', $fileName, 'local');

            // Save the storage path to node data
            $data['attachment'] = $path;
        }
    }

    /**
     * Get validation rules for attachment based on message type
     */
    private function getAttachmentValidationRules(string $messageType): array
    {
        return match ($messageType) {
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
            'audio' => ['required', 'file', 'mimes:ogg,mp3,wav,m4a,opus,webm', 'max:16384'],
            'video' => ['required', 'file', 'mimes:mp4,avi,mov,wmv,flv,webm,mkv', 'max:51200'],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv', 'max:102400'],
            default => ['nullable'],
        };
    }

    /**
     * Get validation rules for node data based on type.
     */
    private function getValidationRulesForNodeType(string $type): array
    {
        return match ($type) {
            'message' => [
                'body' => ['nullable', 'string'],
                'message_type' => ['required', 'string', Rule::in(['text', 'image', 'audio', 'video', 'document'])],
                'attachment' => ['nullable', 'string'], // Storage path if file already uploaded
                'delay' => ['nullable', 'integer', 'min:0'],
                'wait_for_reply' => ['nullable', 'boolean'],
            ],
            'response' => [
                'body' => ['nullable', 'string'],
                'message_type' => ['required', 'string', Rule::in(['text', 'image', 'audio', 'video', 'document'])],
                'attachment' => ['nullable', 'string'], // Storage path if file already uploaded
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
            default => [],
        };
    }
}
