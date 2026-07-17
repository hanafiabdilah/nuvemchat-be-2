<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\SenderType;
use App\Events\ConversationHandoff;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\AiHubAgent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\BusinessHours;
use App\Services\Message\MessageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlowExecutor
{
    /**
     * Safety-net cap on AI agent turns within a single AIAgent node before
     * forcing handoff. Prevents runaway loops if the hub's handoff signal
     * never fires.
     */
    protected const AI_MAX_TURNS = 20;

    protected MessageService $messageService;

    protected AiAgentHubTenantService $aiAgentHubService;

    public function __construct()
    {
        $this->messageService = new MessageService();
        $this->aiAgentHubService = new AiAgentHubTenantService();
    }

    /**
     * Start a flow for a conversation
     */
    public function startFlow(Conversation $conversation): void
    {
        $connection = $conversation->connection;

        if (!$connection->flow_id) {
            return;
        }

        // Only start flow for Pending conversations (waiting for admin)
        if ($conversation->status !== ConversationStatus::Pending) {
            Log::info('FlowExecutor: Cannot start flow, conversation is not pending', [
                'conversation_id' => $conversation->id,
                'status' => $conversation->status->value,
            ]);
            return;
        }

        // Check if flow is already running
        $existingState = FlowState::where('conversation_id', $conversation->id)
            ->where('flow_id', $connection->flow_id)
            ->first();

        if ($existingState) {
            return; // Flow already running
        }

        // Find the Start node
        $startNode = FlowNode::where('flow_id', $connection->flow_id)
            ->where('type', NodeType::Start)
            ->first();

        if (!$startNode) {
            Log::warning('FlowExecutor: No start node found', [
                'flow_id' => $connection->flow_id,
            ]);
            return;
        }

        // Create flow state
        $flowState = FlowState::create([
            'conversation_id' => $conversation->id,
            'flow_id' => $connection->flow_id,
            'current_node_id' => $startNode->id,
            'state_data' => [],
            'status' => FlowStateStatus::Running,
        ]);

        // Execute from start node
        $this->executeFromNode($flowState, $startNode);
    }

    /**
     * Execute flow from a specific node
     */
    protected function executeFromNode(FlowState $flowState, FlowNode $node): void
    {
        // Check if conversation is still flow-eligible (Pending or AI-handling) before executing
        $conversation = $flowState->conversation->fresh();

        if (!in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            Log::info('FlowExecutor: Flow stopped, conversation is no longer flow-eligible (flow state preserved)', [
                'conversation_id' => $conversation->id,
                'status' => $conversation->status->value,
                'flow_state_id' => $flowState->id,
            ]);

            // Don't delete flow state - preserve context data
            return;
        }

        Log::info('FlowExecutor: Executing node', [
            'node_id' => $node->id,
            'node_type' => $node->type->value,
        ]);

        // Handle different node types
        switch ($node->type) {
            case NodeType::Start:
                // Start node just moves to the next node
                $this->moveToNextNode($flowState, $node);
                break;

            case NodeType::Message:
                $this->executeMessageNode($flowState, $node);
                break;

            case NodeType::Response:
                $this->executeResponseNode($flowState, $node);
                break;

            case NodeType::Condition:
                $this->executeConditionNode($flowState, $node);
                break;

            case NodeType::Tagging:
                $this->executeTaggingNode($flowState, $node);
                break;

            case NodeType::AIAgent:
                $this->executeAIAgentNode($flowState, $node);
                break;

            case NodeType::HttpRequest:
                $this->executeHttpNode($flowState, $node);
                break;

            default:
                Log::warning('FlowExecutor: Unsupported node type', [
                    'node_type' => $node->type->value,
                ]);
                break;
        }
    }

    /**
     * Execute a Message node - sends a message to the conversation
     */
    protected function executeMessageNode(FlowState $flowState, FlowNode $node): void
    {
        $data = $node->data;
        $conversation = $flowState->conversation;

        try {
            // Apply delay if specified
            if (isset($data['delay']) && $data['delay'] > 0) {
                sleep($data['delay']);
            }

            // Dispatch send by message_type (text / image / audio / video / document)
            $message = $this->sendByMessageType($conversation, $data, $flowState);

            if ($message) {
                Log::info('FlowExecutor: Message sent', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'wait_for_reply' => $data['wait_for_reply'] ?? true,
                ]);

                broadcast(new MessageReceived($message));

                // Check if we should wait for reply or proceed immediately
                $waitForReply = $data['wait_for_reply'] ?? true;

                if ($waitForReply) {
                    // Move to next node WITHOUT executing it (wait for user response)
                    $this->moveToNextNodeWithoutExecute($flowState, $node);
                } else {
                    // Move to next node and execute it immediately
                    $this->moveToNextNode($flowState, $node);
                }
            } else {
                Log::error('FlowExecutor: Failed to send message', [
                    'node_id' => $node->id,
                    'conversation_id' => $conversation->id,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing message node', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Execute a Response node - sends a prompt and WAITS for user input
     * Note: Does NOT move to next node - waits for resumeFlow() with user input
     */
    protected function executeResponseNode(FlowState $flowState, FlowNode $node): void
    {
        $data = $node->data;
        $conversation = $flowState->conversation;

        try {
            // Dispatch send by message_type (text / image / audio / video / document)
            $message = $this->sendByMessageType($conversation, $data, $flowState);

            if ($message) {
                Log::info('FlowExecutor: Response prompt sent, waiting for user input', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'node_id' => $node->id,
                    'variable_key' => $data['variable_key'] ?? null,
                ]);

                broadcast(new MessageReceived($message));

                // Set flag to indicate prompt has been sent
                $stateData = $flowState->state_data ?? [];
                $stateData["_response_sent_{$node->id}"] = true;
                $flowState->update(['state_data' => $stateData]);

                // DON'T move to next node - stay on this Response node
                // Wait for user to reply, which will be handled in resumeFlow()
            } else {
                Log::error('FlowExecutor: Failed to send response prompt', [
                    'node_id' => $node->id,
                    'conversation_id' => $conversation->id,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing response node', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Execute a tagging node - add or remove tags from conversation
     */
    protected function executeTaggingNode(FlowState $flowState, FlowNode $node): void
    {
        try {
            $conversation = $flowState->conversation;
            $data = $node->data;

            $action = $data['action'] ?? 'add';
            $tagIds = $data['tags'] ?? [];

            if (empty($tagIds)) {
                Log::warning('FlowExecutor: No tags provided for tagging node', [
                    'node_id' => $node->id,
                ]);
                $this->moveToNextNode($flowState, $node);
                return;
            }

            Log::info('FlowExecutor: Executing tagging node', [
                'node_id' => $node->id,
                'action' => $action,
                'tag_ids' => $tagIds,
                'conversation_id' => $conversation->id,
            ]);

            if ($action === 'add') {
                // Add tags to conversation (sync will only add tags that don't exist)
                $conversation->tags()->syncWithoutDetaching($tagIds);

                Log::info('FlowExecutor: Tags added to conversation', [
                    'conversation_id' => $conversation->id,
                    'tag_ids' => $tagIds,
                ]);
            } elseif ($action === 'remove') {
                // Remove tags from conversation
                $conversation->tags()->detach($tagIds);

                Log::info('FlowExecutor: Tags removed from conversation', [
                    'conversation_id' => $conversation->id,
                    'tag_ids' => $tagIds,
                ]);
            }

            // Move to next node
            $this->moveToNextNode($flowState, $node);

        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing tagging node', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);

            // Continue flow even on error
            $this->moveToNextNode($flowState, $node);
        }
    }

    /**
     * Execute an HTTP Request node.
     *
     * Interpolates {{variable}} tokens (from flow state) into the URL, header
     * values and body, fires the request, stores mapped response fields into
     * flow state, then branches to the "success" edge (2xx) or "error" edge
     * (non-2xx / timeout / exception). A missing branch edge ends this path
     * gracefully rather than failing the whole flow.
     */
    protected function executeHttpNode(FlowState $flowState, FlowNode $node): void
    {
        $data = $node->data ?? [];
        $stateData = $flowState->state_data ?? [];

        $method = strtoupper($data['method'] ?? 'GET');
        $url = trim($this->interpolateVariables((string) ($data['url'] ?? ''), $flowState));

        if ($url === '') {
            Log::warning('FlowExecutor: HTTP node has no URL, taking error branch', [
                'node_id' => $node->id,
            ]);
            $this->moveToNextNodeByBranch($flowState, $node, 'error');
            return;
        }

        try {
            // Build interpolated headers from the list of { key, value } rows.
            $headers = [];
            foreach ((array) ($data['headers'] ?? []) as $header) {
                $key = trim((string) ($header['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $headers[$key] = $this->interpolateVariables((string) ($header['value'] ?? ''), $flowState);
            }

            $timeout = (int) ($data['timeout'] ?? 15);
            if ($timeout <= 0) {
                $timeout = 15;
            }

            $request = Http::withHeaders($headers)
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 10));

            // Prepare the body for write verbs. If it parses as JSON we send it
            // as a JSON payload; otherwise it goes out as a raw string.
            $jsonBody = null;

            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $rawBody = $this->interpolateVariables((string) ($data['body'] ?? ''), $flowState);

                if (trim($rawBody) !== '') {
                    $decoded = json_decode($rawBody, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $jsonBody = $decoded;
                    } else {
                        $request = $request->withBody($rawBody, $headers['Content-Type'] ?? 'text/plain');
                    }
                }
            }

            $response = match ($method) {
                'POST' => $jsonBody !== null ? $request->post($url, $jsonBody) : $request->post($url),
                'PUT' => $jsonBody !== null ? $request->put($url, $jsonBody) : $request->put($url),
                'PATCH' => $jsonBody !== null ? $request->patch($url, $jsonBody) : $request->patch($url),
                'DELETE' => $jsonBody !== null ? $request->delete($url, $jsonBody) : $request->delete($url),
                default => $request->get($url),
            };

            // Re-read state in case a mapping key overlaps with something set
            // earlier in this run, then store mapped response fields.
            $stateData = $flowState->fresh()->state_data ?? $stateData;

            $json = null;
            try {
                $json = $response->json();
            } catch (\Throwable $e) {
                $json = null; // response wasn't JSON — only http_status/raw_body usable
            }

            foreach ((array) ($data['response_mappings'] ?? []) as $mapping) {
                $variable = trim((string) ($mapping['variable'] ?? ''));
                if ($variable === '') {
                    continue;
                }

                $path = trim((string) ($mapping['path'] ?? ''));

                $value = match ($path) {
                    'http_status' => $response->status(),
                    'raw_body', '' => $response->body(),
                    default => is_array($json) ? data_get($json, $path) : null,
                };

                // Flatten arrays/objects so single-depth Condition lookups still work.
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                $stateData[$variable] = $value;
            }

            $flowState->update(['state_data' => $stateData]);

            $branch = $response->successful() ? 'success' : 'error';

            Log::info('FlowExecutor: HTTP node executed', [
                'node_id' => $node->id,
                'method' => $method,
                'status' => $response->status(),
                'branch' => $branch,
            ]);

            $this->moveToNextNodeByBranch($flowState, $node, $branch);
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing HTTP node, taking error branch', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);

            $this->moveToNextNodeByBranch($flowState, $node, 'error');
        }
    }

    /**
     * Replace {{token}} placeholders in a template with values resolved from the
     * flow context. This is the flow engine's only string-interpolation
     * mechanism — used for HTTP node url/headers/body and for the text of
     * Message/Response nodes sent to the conversation.
     *
     * Supported tokens (unknown tokens resolve to an empty string):
     *   {{judul}}              → flow state variable "judul" (bare = variable.*)
     *   {{variable.judul}}     → same as above, explicit
     *   {{contact.name}}       → the contact's field
     *   {{conversation.status}}→ the conversation's field
     */
    protected function interpolateVariables(string $template, FlowState $flowState): string
    {
        if ($template === '' || !str_contains($template, '{{')) {
            return $template;
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($matches) use ($flowState) {
            $value = $this->resolveTemplateToken($flowState, $matches[1]);

            if (is_array($value)) {
                return json_encode($value);
            }

            return $value === null ? '' : (string) $value;
        }, $template);
    }

    /**
     * Resolve a single {{token}} to its value. A bare key (no dot) is treated as
     * a flow-state variable; a dotted key delegates to getFieldValue() so
     * contact.*, conversation.* and variable.* all work the same in templates
     * as they do in Condition nodes.
     */
    protected function resolveTemplateToken(FlowState $flowState, string $key)
    {
        if (!str_contains($key, '.')) {
            return $flowState->state_data[$key] ?? null;
        }

        return $this->getFieldValue($flowState, $key);
    }

    /**
     * Move to the next node along a named branch edge (e.g. 'success'/'error'
     * for an HTTP node), matched against the edge's condition_value. A missing
     * branch edge ends this path quietly — an unwired branch is a valid design.
     */
    protected function moveToNextNodeByBranch(FlowState $flowState, FlowNode $currentNode, string $branch): void
    {
        $edge = $currentNode->outgoingEdges()
            ->where('condition_value', $branch)
            ->first();

        if (!$edge) {
            Log::info('FlowExecutor: No edge for branch, flow path ends (flow state preserved)', [
                'node_id' => $currentNode->id,
                'branch' => $branch,
                'flow_state_id' => $flowState->id,
            ]);
            return;
        }

        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            $flowState->update([
                'status' => FlowStateStatus::Failed,
                'completed_at' => now(),
            ]);

            Log::error('FlowExecutor: Next node not found for branch (flow state preserved)', [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
                'flow_state_id' => $flowState->id,
            ]);
            return;
        }

        $flowState->update(['current_node_id' => $nextNode->id]);

        Log::info('FlowExecutor: Moved to next node via branch', [
            'branch' => $branch,
            'next_node_id' => $nextNode->id,
            'next_node_type' => $nextNode->type->value,
        ]);

        $this->executeFromNode($flowState, $nextNode);
    }

    /**
     * Execute a condition node - evaluate condition and branch to TRUE or FALSE path
     */
    protected function executeConditionNode(FlowState $flowState, FlowNode $node): void
    {
        try {
            $conversation = $flowState->conversation;
            $data = $node->data;

            $field = $data['field'] ?? '';
            $operator = $data['operator'] ?? 'equals';
            $expectedValue = $this->interpolateVariables((string) ($data['value'] ?? ''), $flowState);

            Log::info('FlowExecutor: Evaluating condition', [
                'node_id' => $node->id,
                'field' => $field,
                'operator' => $operator,
                'expected_value' => $expectedValue,
            ]);

            // Evaluate the condition
            $result = $this->evaluateCondition($flowState, $field, $operator, $expectedValue);

            Log::info('FlowExecutor: Condition evaluated', [
                'node_id' => $node->id,
                'result' => $result ? 'true' : 'false',
            ]);

            // Move to the appropriate branch (true or false)
            $this->moveToNextNodeByCondition($flowState, $node, $result);

        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing condition node', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);

            // On error, treat as false condition
            $this->moveToNextNodeByCondition($flowState, $node, false);
        }
    }

    /**
     * Evaluate a condition based on field, operator, and value
     */
    protected function evaluateCondition(FlowState $flowState, string $field, string $operator, $expectedValue): bool
    {
        // Get the actual value from the field
        $actualValue = $this->getFieldValue($flowState, $field);

        Log::debug('FlowExecutor: Condition comparison', [
            'field' => $field,
            'actual_value' => $actualValue,
            'operator' => $operator,
            'expected_value' => $expectedValue,
        ]);

        // Evaluate based on operator
        return match($operator) {
            'equals' => $actualValue == $expectedValue,
            'not_equals' => $actualValue != $expectedValue,
            'contains' => is_string($actualValue) && str_contains($actualValue, $expectedValue),
            'not_contains' => is_string($actualValue) && !str_contains($actualValue, $expectedValue),
            'greater_than' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue > $expectedValue,
            'less_than' => is_numeric($actualValue) && is_numeric($expectedValue) && $actualValue < $expectedValue,
            'is_empty' => empty($actualValue),
            'is_not_empty' => !empty($actualValue),
            default => false,
        };
    }

    /**
     * Get field value from conversation, contact, or state data
     */
    protected function getFieldValue(FlowState $flowState, string $field)
    {
        $conversation = $flowState->conversation;
        $parts = explode('.', $field);

        if (count($parts) < 2) {
            return null;
        }

        $source = $parts[0]; // 'variable', 'contact', 'conversation', 'service_hours'
        $key = $parts[1];

        return match($source) {
            'variable' => $flowState->state_data[$key] ?? null,
            'contact' => $conversation->contact->{$key} ?? null,
            'conversation' => $conversation->{$key} ?? null,
            // "service_hours.is_open" → "true"/"false" so a Condition node can
            // branch on whether the conversation's connection is currently
            // within its service hours.
            'service_hours' => $key === 'is_open'
                ? (BusinessHours::isOpen($conversation->connection) ? 'true' : 'false')
                : null,
            default => null,
        };
    }

    /**
     * Validate user input based on validation type
     */
    protected function validateInput(string $input, ?string $validationType): bool
    {
        if (!$validationType || $validationType === 'any') {
            return true; // Accept any input
        }

        return match($validationType) {
            'number' => is_numeric($input),
            'email' => filter_var($input, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => $this->validatePhoneNumber($input),
            default => true,
        };
    }

    /**
     * Validate phone number (basic validation)
     */
    protected function validatePhoneNumber(string $phone): bool
    {
        // Remove common phone number characters
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);

        // Check if it has at least 8 digits (minimum valid phone length)
        // and starts with + or digit
        return strlen($cleaned) >= 8 && preg_match('/^[+]?[0-9]{8,}$/', $cleaned);
    }

    /**
     * Move to next node based on condition result (TRUE or FALSE branch)
     */
    protected function moveToNextNodeByCondition(FlowState $flowState, FlowNode $currentNode, bool $conditionResult): void
    {
        // Find the edge with matching condition_value
        $conditionValue = $conditionResult ? 'true' : 'false';

        $edge = $currentNode->outgoingEdges()
            ->where('condition_value', $conditionValue)
            ->first();

        if (!$edge) {
            // No edge found for this condition result
            $flowState->update([
                'status' => FlowStateStatus::Failed,
                'completed_at' => now(),
            ]);

            Log::error('FlowExecutor: No edge found for condition result (flow state preserved)', [
                'node_id' => $currentNode->id,
                'condition_result' => $conditionValue,
                'flow_state_id' => $flowState->id,
            ]);
            return;
        }

        // Load the next node
        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            $flowState->update([
                'status' => FlowStateStatus::Failed,
                'completed_at' => now(),
            ]);

            Log::error('FlowExecutor: Next node not found (flow state preserved)', [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
                'flow_state_id' => $flowState->id,
            ]);
            return;
        }

        // Update flow state
        $flowState->update([
            'current_node_id' => $nextNode->id,
        ]);

        Log::info('FlowExecutor: Moved to next node via condition', [
            'condition_result' => $conditionValue,
            'next_node_id' => $nextNode->id,
            'next_node_type' => $nextNode->type->value,
        ]);

        // Execute the next node
        $this->executeFromNode($flowState, $nextNode);
    }


    /**
     * Move to the next node in the flow and execute it
     */
    protected function moveToNextNode(FlowState $flowState, FlowNode $currentNode): void
    {
        // Find the next node via edge
        $edge = $currentNode->outgoingEdges()->first();

        if (!$edge) {
            // No next node, flow ends
            Log::info('FlowExecutor: Flow completed (no next node, flow state preserved)', [
                'flow_state_id' => $flowState->id,
                'current_node_id' => $currentNode->id,
            ]);

            // Don't delete flow state - preserve context data for completed flows
            return;
        }

        // Load the next node
        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            $flowState->update([
                'status' => FlowStateStatus::Failed,
                'completed_at' => now(),
            ]);

            Log::error('FlowExecutor: Next node not found (flow state preserved)', [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
                'flow_state_id' => $flowState->id,
                'status' => 'failed',
            ]);

            // Don't delete flow state - preserve context data even on error
            return;
        }

        // Update flow state
        $flowState->update([
            'current_node_id' => $nextNode->id,
        ]);

        // Execute the next node
        $this->executeFromNode($flowState, $nextNode);
    }

    /**
     * Move to the next node WITHOUT executing it (wait for user interaction)
     */
    protected function moveToNextNodeWithoutExecute(FlowState $flowState, FlowNode $currentNode): void
    {
        // Find the next node via edge
        $edge = $currentNode->outgoingEdges()->first();

        if (!$edge) {
            // No next node, flow ends
            $flowState->update([
                'status' => FlowStateStatus::Completed,
                'completed_at' => now(),
            ]);

            Log::info('FlowExecutor: Flow completed (no next node, flow state preserved)', [
                'flow_state_id' => $flowState->id,
                'current_node_id' => $currentNode->id,
                'status' => 'completed',
            ]);

            // Don't delete flow state - preserve context data for completed flows
            return;
        }

        // Load the next node
        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            $flowState->update([
                'status' => FlowStateStatus::Failed,
                'completed_at' => now(),
            ]);

            Log::error('FlowExecutor: Next node not found (flow state preserved)', [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
                'flow_state_id' => $flowState->id,
                'status' => 'failed',
            ]);

            // Don't delete flow state - preserve context data even on error
            return;
        }

        // Update flow state to the next node WITHOUT executing
        $flowState->update([
            'current_node_id' => $nextNode->id,
        ]);

        Log::info('FlowExecutor: Moved to next node, waiting for user interaction', [
            'flow_state_id' => $flowState->id,
            'next_node_id' => $nextNode->id,
            'next_node_type' => $nextNode->type->value,
        ]);
    }

    /**
     * Stop a flow for a conversation (called when admin accepts)
     * Note: Flow state is preserved for context data
     */
    public function stopFlow(Conversation $conversation): void
    {
        $flowState = FlowState::where('conversation_id', $conversation->id)->first();

        if ($flowState) {
            // Mark flow as stopped with timestamp
            $flowState->update([
                'status' => FlowStateStatus::Stopped,
                'completed_at' => now(),
            ]);

            Log::info('FlowExecutor: Flow stopped due to admin handover (flow state preserved)', [
                'conversation_id' => $conversation->id,
                'flow_state_id' => $flowState->id,
                'current_node_id' => $flowState->current_node_id,
                'status' => 'stopped',
            ]);

            // Don't delete flow state - preserve context data
            // Flow will automatically stop executing due to status check
        }
    }

    /**
     * Resolve the AIAgent node's service-hours behaviour (a 3-way mode):
     *   - always_ai           : AI always handles; handoff just moves to next node
     *   - handoff_in_hours    : AI handles, then hands a human the chat (in hours)
     *   - human_only_in_hours : within hours skip AI entirely → human; AI otherwise
     *
     * Falls back to the legacy `human_handoff_enabled` boolean for flows saved
     * before the mode field existed.
     */
    protected function handoffMode(array $data): string
    {
        $mode = $data['service_hours_behavior'] ?? null;

        if (in_array($mode, ['always_ai', 'handoff_in_hours', 'human_only_in_hours'], true)) {
            return $mode;
        }

        return ! empty($data['human_handoff_enabled']) ? 'handoff_in_hours' : 'always_ai';
    }

    /**
     * Route an AIAgent-node handoff. Unless the node is in `always_ai` mode, the
     * conversation is handed to the unassigned human queue — but only within the
     * tenant's service hours. Outside those hours fall back to the flow's next
     * node; for an AI-initiated request the AI keeps handling and sends the away
     * message.
     *
     * @param bool $aiCanContinue true only for the hub's intentional handoff
     *        request. Failure paths (no agent / error / max turns) cannot stay
     *        with the AI and always fall through to the next node.
     */
    protected function routeHandoff(FlowState $flowState, FlowNode $node, string $reason, bool $aiCanContinue): void
    {
        if ($this->handoffMode($node->data ?? []) === 'always_ai') {
            // AI is done with this node; release it from the AI tab before advancing.
            $this->releaseAiHandling($flowState->conversation);
            $this->moveToNextNode($flowState, $node);
            return;
        }

        $connection = $flowState->conversation->connection;

        if (BusinessHours::isOpen($connection)) {
            $this->transferToHuman($flowState, $reason);
            return;
        }

        // Outside service hours: no human available.
        if ($aiCanContinue) {
            $this->sendAwayMessageOnce($flowState, $connection);
            return; // stay on this AIAgent node, keep handling with the AI
        }

        // AI cannot continue and no human is available — advance the flow.
        $this->releaseAiHandling($flowState->conversation);
        $this->moveToNextNode($flowState, $node);
    }

    /**
     * Mark the conversation as being handled by the AI. Only flips a conversation
     * that is still in the unassigned Pending queue — never clobbers Active (a
     * human already took over) or Resolved. Broadcasts so the dashboard moves the
     * conversation into the "AI" tab in realtime.
     */
    protected function markAiHandling(Conversation $conversation): void
    {
        if ($conversation->status !== ConversationStatus::Pending) {
            return;
        }

        $conversation->forceFill(['status' => ConversationStatus::AiHandling])->save();

        broadcast(new ConversationUpdated($conversation->fresh()));

        Log::info('FlowExecutor: Conversation now handled by AI', [
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * The AI is done handling (handed off to the flow's next node / flow ended).
     * Drop the conversation back into the Pending queue so it is no longer shown
     * as AI-handled. No-op unless it is currently AI-handling.
     */
    protected function releaseAiHandling(Conversation $conversation): void
    {
        if ($conversation->status !== ConversationStatus::AiHandling) {
            return;
        }

        $conversation->forceFill(['status' => ConversationStatus::Pending])->save();

        broadcast(new ConversationUpdated($conversation->fresh()));

        Log::info('FlowExecutor: AI released conversation back to Pending', [
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Hand the conversation to a human: flag it, drop it back into the
     * unassigned Pending queue, stop the AI, and notify every agent in realtime.
     */
    protected function transferToHuman(FlowState $flowState, string $reason): void
    {
        $conversation = $flowState->conversation;

        $conversation->forceFill([
            'needs_human' => true,
            'handoff_reason' => $reason,
            'handoff_at' => now(),
            'user_id' => null,                 // unassigned — any agent can pick it up
            'status' => ConversationStatus::Pending,
        ])->save();

        // Stop the AI so it no longer auto-replies; flow state is preserved.
        $this->stopFlow($conversation);

        $fresh = $conversation->fresh();

        broadcast(new ConversationHandoff($fresh, $reason));
        broadcast(new ConversationUpdated($fresh));

        Log::info('FlowExecutor: Conversation handed off to human queue', [
            'conversation_id' => $conversation->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Send the connection's configured away message at most once per flow run.
     */
    protected function sendAwayMessageOnce(FlowState $flowState, Connection $connection): void
    {
        $stateData = $flowState->state_data ?? [];

        if (! empty($stateData['_away_message_sent'])) {
            return;
        }

        $awayMessage = BusinessHours::awayMessage($connection);

        if ($awayMessage) {
            $awayMessage = $this->interpolateVariables($awayMessage, $flowState);

            $message = $this->messageService->sendMessage($flowState->conversation, [
                'message' => $awayMessage,
            ]);

            if ($message) {
                $message->update(['sent_by_flow_id' => $flowState->flow_id]);
                broadcast(new MessageReceived($message));
            }
        }

        $stateData['_away_message_sent'] = true;
        $flowState->update(['state_data' => $stateData]);
    }

    /**
     * Resume a flow after receiving user input
     */
    public function resumeFlow(Conversation $conversation, string $userInput): void
    {
        // Only resume flow for flow-eligible conversations (Pending queue or active AI turn)
        if (!in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            Log::info('FlowExecutor: Cannot resume flow, conversation is not flow-eligible', [
                'conversation_id' => $conversation->id,
                'status' => $conversation->status->value,
            ]);

            // Stop any active flow
            $this->stopFlow($conversation);
            return;
        }

        $flowState = FlowState::where('conversation_id', $conversation->id)->first();

        if (!$flowState) {
            return; // No active flow
        }

        // Don't resume flows that are already completed, stopped, or failed
        if ($flowState->status !== FlowStateStatus::Running) {
            Log::info('FlowExecutor: Cannot resume flow, flow is not running', [
                'conversation_id' => $conversation->id,
                'flow_state_id' => $flowState->id,
                'status' => $flowState->status->value,
            ]);
            return;
        }

        $currentNode = $flowState->currentNode;

        if (!$currentNode) {
            Log::error('FlowExecutor: Current node not found (flow state preserved)', [
                'flow_state_id' => $flowState->id,
            ]);

            // Don't delete flow state - preserve for debugging
            return;
        }

        Log::info('FlowExecutor: Resuming flow from user input', [
            'conversation_id' => $conversation->id,
            'current_node_id' => $currentNode->id,
            'current_node_type' => $currentNode->type->value,
            'user_input' => substr($userInput, 0, 50), // Log first 50 chars only
        ]);

        // Special handling for AIAgent nodes - feed user input to the agent
        if ($currentNode->type === NodeType::AIAgent) {
            $latestIncomingId = Message::where('conversation_id', $conversation->id)
                ->where('sender_type', SenderType::Incoming)
                ->latest('id')
                ->value('id');

            $this->handleAIAgentInput($flowState, $currentNode, $userInput, $latestIncomingId);
            return;
        }

        // Special handling for Response nodes - validate and store input
        if ($currentNode->type === NodeType::Response) {
            // Check if this Response node has sent its prompt yet
            $stateData = $flowState->state_data ?? [];
            $promptSentFlag = "_response_sent_{$currentNode->id}";

            if (!isset($stateData[$promptSentFlag])) {
                // Prompt hasn't been sent yet - execute Response node first (send prompt)
                Log::info('FlowExecutor: Response node reached but prompt not sent yet, executing Response node', [
                    'node_id' => $currentNode->id,
                    'conversation_id' => $conversation->id,
                ]);

                $this->executeResponseNode($flowState, $currentNode);
                return;
            }

            // Prompt already sent - handle user input
            $this->handleResponseNodeInput($flowState, $currentNode, $userInput);
            return;
        }

        // For other node types, execute the current node
        $this->executeFromNode($flowState, $currentNode);
    }

    /**
     * Handle user input for Response node - validate, store, and move to next
     */
    protected function handleResponseNodeInput(FlowState $flowState, FlowNode $node, string $userInput): void
    {
        $data = $node->data;
        $conversation = $flowState->conversation;
        $variableKey = $data['variable_key'] ?? null;
        $validationType = $data['validation'] ?? 'any';
        $errorMessage = $this->interpolateVariables(
            (string) ($data['error_message'] ?? 'Input tidak valid. Silakan coba lagi.'),
            $flowState
        );

        Log::info('FlowExecutor: Processing Response node input', [
            'node_id' => $node->id,
            'variable_key' => $variableKey,
            'validation_type' => $validationType,
            'input_length' => strlen($userInput),
        ]);

        // Validate input
        $isValid = $this->validateInput($userInput, $validationType);

        if (!$isValid) {
            // Send error message and ask again (don't move to next node)
            Log::warning('FlowExecutor: Response validation failed', [
                'node_id' => $node->id,
                'validation_type' => $validationType,
                'input' => substr($userInput, 0, 50),
            ]);

            try {
                $errorMessageData = [
                    'message' => $errorMessage,
                ];

                $message = $this->messageService->sendMessage($conversation, $errorMessageData);

                if ($message) {
                    $message->update(['sent_by_flow_id' => $flowState->flow_id]);
                    broadcast(new MessageReceived($message));
                }
            } catch (\Throwable $th) {
                Log::error('FlowExecutor: Failed to send validation error message', [
                    'error' => $th->getMessage(),
                ]);
            }

            // Stay on current Response node - don't move
            return;
        }

        // Valid input - store in state_data and clear the flag
        $stateData = $flowState->state_data ?? [];

        if ($variableKey) {
            $stateData[$variableKey] = $userInput;

            Log::info('FlowExecutor: Response input stored in state_data', [
                'node_id' => $node->id,
                'variable_key' => $variableKey,
                'value' => substr($userInput, 0, 50),
            ]);
        }

        // Clear the response sent flag since we're moving to next node
        unset($stateData["_response_sent_{$node->id}"]);

        $flowState->update([
            'state_data' => $stateData,
        ]);

        // Move to next node and execute it
        $this->moveToNextNode($flowState, $node);
    }

    /**
     * Execute an AIAgent node.
     *
     * First turn is always answered with the configured `welcoming_message`
     * — the AI is never called on the first interaction. This avoids the
     * common case where an LLM with no prior context responds awkwardly
     * to bare greetings like "Halo". Subsequent turns go through the AI.
     *
     * If no fresh incoming message exists, the node simply waits — the
     * next user reply will be routed here via resumeFlow().
     */
    protected function executeAIAgentNode(FlowState $flowState, FlowNode $node): void
    {
        $data = $node->data ?? [];

        // Mode "AI only outside service hours": within service hours this node
        // must not run the AI at all — hand straight to the human queue and stop.
        if ($this->handoffMode($data) === 'human_only_in_hours'
            && BusinessHours::isOpen($flowState->conversation->connection)) {
            Log::info('FlowExecutor: AIAgent skipped — within service hours, routing to human', [
                'node_id' => $node->id,
                'conversation_id' => $flowState->conversation_id,
            ]);
            $this->transferToHuman($flowState, 'service_hours');
            return;
        }

        // From here the AI owns the conversation — surface it in the "AI" tab.
        $this->markAiHandling($flowState->conversation);

        $stateData = $flowState->state_data ?? [];
        $turnsKey = "_ai_turns_{$node->id}";
        $lastProcessedKey = "_ai_last_processed_message_id_{$node->id}";

        if (!isset($stateData[$turnsKey])) {
            $stateData[$turnsKey] = 0;
            $flowState->update(['state_data' => $stateData]);
        }

        $lastProcessedId = $stateData[$lastProcessedKey] ?? 0;

        $pendingMessage = Message::where('conversation_id', $flowState->conversation_id)
            ->where('sender_type', SenderType::Incoming)
            ->where('id', '>', $lastProcessedId)
            ->orderBy('id')
            ->first();

        if (!$pendingMessage) {
            Log::info('FlowExecutor: AIAgent node reached, no pending input — waiting', [
                'node_id' => $node->id,
                'conversation_id' => $flowState->conversation_id,
                'turns' => $stateData[$turnsKey],
            ]);
            return;
        }

        $isFirstTurn = ($stateData[$turnsKey] ?? 0) === 0;

        if ($isFirstTurn) {
            $welcomingMessage = trim((string) ($data['welcoming_message'] ?? ''));

            if ($welcomingMessage === '') {
                Log::error('FlowExecutor: AIAgent node missing required welcoming_message, skipping AI on first turn', [
                    'node_id' => $node->id,
                    'conversation_id' => $flowState->conversation_id,
                ]);

                $stateData[$turnsKey] = 1;
                $stateData[$lastProcessedKey] = $pendingMessage->id;
                $flowState->update(['state_data' => $stateData]);
                return;
            }

            $this->sendAIAgentWelcome($flowState, $node, $welcomingMessage, $pendingMessage->id);
            return;
        }

        Log::info('FlowExecutor: AIAgent processing pending message on node entry', [
            'node_id' => $node->id,
            'conversation_id' => $flowState->conversation_id,
            'message_id' => $pendingMessage->id,
        ]);

        $this->handleAIAgentInput($flowState, $node, $pendingMessage->body ?? '', $pendingMessage->id);
    }

    /**
     * Send the configured welcoming message and advance the turn counter
     * without calling the AI. The triggering user message is marked as
     * processed so the next user reply will be routed to the AI normally.
     */
    protected function sendAIAgentWelcome(FlowState $flowState, FlowNode $node, string $welcomingMessage, int $triggeringMessageId): void
    {
        $conversation = $flowState->conversation;
        $welcomingMessage = $this->interpolateVariables($welcomingMessage, $flowState);

        try {
            $message = $this->messageService->sendMessage($conversation, [
                'message' => $welcomingMessage,
            ]);

            if ($message) {
                $message->update([
                    'sent_by_flow_id' => $flowState->flow_id,
                    'sent_by_ai_hub_agent_id' => $node->data['ai_hub_agent_id'] ?? null,
                    'meta' => array_merge((array) ($message->meta ?? []), [
                        'ai_welcome' => true,
                        'ai_hub_agent_id' => $node->data['ai_hub_agent_id'] ?? null,
                    ]),
                ]);

                broadcast(new MessageReceived($message));
            }
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Failed to send AIAgent welcoming message', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);
        }

        $stateData = $flowState->state_data ?? [];
        $stateData["_ai_turns_{$node->id}"] = ($stateData["_ai_turns_{$node->id}"] ?? 0) + 1;
        $stateData["_ai_last_processed_message_id_{$node->id}"] = $triggeringMessageId;
        $flowState->update(['state_data' => $stateData]);

        Log::info('FlowExecutor: AIAgent welcoming message sent, waiting for next user input', [
            'node_id' => $node->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Handle user input for an AIAgent node: call the hub, relay the reply
     * to the contact, and decide whether to keep looping on this node or
     * hand off to the next node.
     *
     * Handoff path (move to next node):
     *   - Safety-net: turn counter exceeds AI_MAX_TURNS
     *   - Error path: hub call throws (fail open to human)
     *
     * Note: hub-side handoff signal (humanRequested / angryCustomer /
     * outOfScope) is not yet wired here. Once the hub exposes a handoff
     * field in the /runs response, parse it from the run record and call
     * moveToNextNode() with the reason stored in state_data.
     */
    protected function handleAIAgentInput(FlowState $flowState, FlowNode $node, string $userInput, ?int $sourceMessageId = null): void
    {
        $data = $node->data;
        $conversation = $flowState->conversation;
        $stateData = $flowState->state_data ?? [];
        $turnsKey = "_ai_turns_{$node->id}";
        $reasonKey = "_ai_handoff_reason_{$node->id}";
        $lastProcessedKey = "_ai_last_processed_message_id_{$node->id}";

        if ($sourceMessageId !== null) {
            $stateData[$lastProcessedKey] = $sourceMessageId;
        }

        $turns = $stateData[$turnsKey] ?? 0;

        if ($turns >= self::AI_MAX_TURNS) {
            Log::warning('FlowExecutor: AIAgent max turns exceeded, forcing handoff', [
                'node_id' => $node->id,
                'turns' => $turns,
                'limit' => self::AI_MAX_TURNS,
            ]);

            $stateData[$reasonKey] = 'max_turns_exceeded';
            $flowState->update(['state_data' => $stateData]);
            $this->routeHandoff($flowState, $node, 'max_turns_exceeded', false);
            return;
        }

        $agentId = $data['ai_hub_agent_id'] ?? null;
        $agent = $agentId ? AiHubAgent::find($agentId) : null;

        if (!$agent) {
            Log::error('FlowExecutor: AIAgent node has no valid agent, handing off', [
                'node_id' => $node->id,
                'ai_hub_agent_id' => $agentId,
            ]);

            $stateData[$reasonKey] = 'agent_missing';
            $flowState->update(['state_data' => $stateData]);
            $this->routeHandoff($flowState, $node, 'agent_missing', false);
            return;
        }

        try {
            $run = $this->aiAgentHubService->runAgent(
                $agent,
                $conversation,
                $userInput,
                $flowState->id,
                $node->id
            );

            $replyText = $run->output_message;

            if (!empty($replyText)) {
                $message = $this->messageService->sendMessage($conversation, [
                    'message' => $replyText,
                ]);

                if ($message) {
                    $message->update([
                        'sent_by_flow_id' => $flowState->flow_id,
                        'sent_by_ai_hub_agent_id' => $agent->id,
                        'meta' => array_merge((array) ($message->meta ?? []), [
                            'ai_generated' => true,
                            'ai_hub_run_id' => $run->id,
                            'ai_hub_agent_id' => $agent->id,
                        ]),
                    ]);

                    $run->update(['message_id' => $message->id]);

                    broadcast(new MessageReceived($message));
                }
            } else {
                Log::warning('FlowExecutor: AIAgent run returned empty reply', [
                    'node_id' => $node->id,
                    'run_id' => $run->id,
                    'status' => $run->status,
                ]);
            }

            if ($run->handoff_triggered) {
                $details = (array) ($run->handoff_details ?? []);
                $reason = $details['trigger']
                    ?? $details['reason']
                    ?? 'ai_requested';

                $stateData[$reasonKey] = $reason;
                $stateData[$turnsKey] = $turns + 1;
                $flowState->update(['state_data' => $stateData]);

                Log::info('FlowExecutor: AIAgent handoff signaled by hub', [
                    'node_id' => $node->id,
                    'run_id' => $run->id,
                    'reason' => $reason,
                    'handoff_details' => $details,
                ]);

                $this->routeHandoff($flowState, $node, $reason, true);
                return;
            }

            $stateData[$turnsKey] = $turns + 1;
            $flowState->update(['state_data' => $stateData]);

            Log::info('FlowExecutor: AIAgent turn completed, waiting for next user input', [
                'node_id' => $node->id,
                'turn' => $turns + 1,
                'run_id' => $run->id,
            ]);

            // Stay on this node — wait for the next user reply.
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error running AIAgent, handing off to human', [
                'node_id' => $node->id,
                'ai_hub_agent_id' => $agent->id,
                'error' => $th->getMessage(),
            ]);

            $stateData[$reasonKey] = 'error';
            $flowState->update(['state_data' => $stateData]);
            $this->routeHandoff($flowState, $node, 'error', false);
        }
    }

    /**
     * Send a message dispatched by `message_type`.
     * Passes `attachment_url` straight through to the channel handlers as a
     * `media_url`, so media is sent by URL (fast-path) without a
     * download/re-upload round trip. Handlers fall back to downloading the URL
     * only when the channel rejects it or needs transcoding.
     */
    protected function sendByMessageType(Conversation $conversation, array $nodeData, ?FlowState $flowState = null): ?Message
    {
        $messageType = $nodeData['message_type'] ?? 'text';
        $body = $nodeData['body'] ?? '';
        $attachmentUrl = $nodeData['attachment_url'] ?? null;

        // Substitute {{variable}} placeholders with values stored in the flow
        // (Response inputs, HTTP response mappings, contact/conversation fields)
        // before the text/media is sent to the conversation.
        if ($flowState) {
            $body = $this->interpolateVariables((string) $body, $flowState);
            if ($attachmentUrl) {
                $attachmentUrl = $this->interpolateVariables((string) $attachmentUrl, $flowState);
            }
        }

        $send = function (?Message $message) use ($flowState): ?Message {
            if ($message && $flowState) {
                $message->update(['sent_by_flow_id' => $flowState->flow_id]);
            }
            return $message;
        };

        if ($messageType === 'text' || !$attachmentUrl) {
            return $send($this->messageService->sendMessage($conversation, [
                'message' => $body,
            ]));
        }

        return $send(match ($messageType) {
            'image' => $this->messageService->sendImage($conversation, [
                'media_url' => $attachmentUrl,
                'message' => $body,
            ]),
            'audio' => $this->messageService->sendAudio($conversation, [
                'media_url' => $attachmentUrl,
            ]),
            'video' => $this->messageService->sendVideo($conversation, [
                'media_url' => $attachmentUrl,
                'message' => $body,
            ]),
            'document' => $this->messageService->sendDocument($conversation, [
                'media_url' => $attachmentUrl,
                'message' => $body,
            ]),
            default => $this->messageService->sendMessage($conversation, [
                'message' => $body,
            ]),
        });
    }
}
