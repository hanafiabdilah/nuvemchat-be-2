<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Services\Message\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlowExecutor
{
    protected MessageService $messageService;

    public function __construct()
    {
        $this->messageService = new MessageService();
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
        // Check if conversation is still Pending before executing
        $conversation = $flowState->conversation->fresh();

        if ($conversation->status !== ConversationStatus::Pending) {
            Log::info('FlowExecutor: Flow stopped, conversation is no longer pending (flow state preserved)', [
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
            $message = $this->sendByMessageType($conversation, $data);

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
            $message = $this->sendByMessageType($conversation, $data);

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
     * Execute a condition node - evaluate condition and branch to TRUE or FALSE path
     */
    protected function executeConditionNode(FlowState $flowState, FlowNode $node): void
    {
        try {
            $conversation = $flowState->conversation;
            $data = $node->data;

            $field = $data['field'] ?? '';
            $operator = $data['operator'] ?? 'equals';
            $expectedValue = $data['value'] ?? '';

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

        $source = $parts[0]; // 'variable', 'contact', 'conversation'
        $key = $parts[1];

        return match($source) {
            'variable' => $flowState->state_data[$key] ?? null,
            'contact' => $conversation->contact->{$key} ?? null,
            'conversation' => $conversation->{$key} ?? null,
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
     * Resume a flow after receiving user input
     */
    public function resumeFlow(Conversation $conversation, string $userInput): void
    {
        // Only resume flow for Pending conversations
        if ($conversation->status !== ConversationStatus::Pending) {
            Log::info('FlowExecutor: Cannot resume flow, conversation is not pending', [
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
        $errorMessage = $data['error_message'] ?? 'Input tidak valid. Silakan coba lagi.';

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
     * Send a message dispatched by `message_type`.
     * Downloads `attachment_url` into a temporary UploadedFile so the
     * existing channel handlers (image/audio/video/document) can consume it.
     */
    protected function sendByMessageType(Conversation $conversation, array $nodeData): ?Message
    {
        $messageType = $nodeData['message_type'] ?? 'text';
        $body = $nodeData['body'] ?? '';
        $attachmentUrl = $nodeData['attachment_url'] ?? null;

        if ($messageType === 'text' || !$attachmentUrl) {
            return $this->messageService->sendMessage($conversation, [
                'message' => $body,
            ]);
        }

        $tempPath = null;
        try {
            $uploadedFile = $this->downloadAsUploadedFile($attachmentUrl, $tempPath);

            if (!$uploadedFile) {
                Log::warning('FlowExecutor: Falling back to text after failing to download attachment', [
                    'attachment_url' => $attachmentUrl,
                    'message_type' => $messageType,
                ]);

                return $this->messageService->sendMessage($conversation, [
                    'message' => $body,
                ]);
            }

            return match ($messageType) {
                'image' => $this->messageService->sendImage($conversation, [
                    'image' => $uploadedFile,
                    'message' => $body,
                ]),
                'audio' => $this->messageService->sendAudio($conversation, [
                    'audio' => $uploadedFile,
                ]),
                'video' => $this->messageService->sendVideo($conversation, [
                    'video' => $uploadedFile,
                    'message' => $body,
                ]),
                'document' => $this->messageService->sendDocument($conversation, [
                    'document' => $uploadedFile,
                    'message' => $body,
                ]),
                default => $this->messageService->sendMessage($conversation, [
                    'message' => $body,
                ]),
            };
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Download a URL to a temp file and wrap it in an UploadedFile (test mode).
     * Returns null on failure. $tempPath is filled with the temp file path
     * so the caller can clean it up.
     */
    protected function downloadAsUploadedFile(string $url, ?string &$tempPath = null): ?UploadedFile
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning('FlowExecutor: Attachment download returned non-success', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $contentType = $response->header('Content-Type') ?: 'application/octet-stream';
            $mime = trim(explode(';', $contentType)[0]);

            $pathFromUrl = parse_url($url, PHP_URL_PATH) ?: '';
            $filename = $pathFromUrl ? basename($pathFromUrl) : '';
            if ($filename === '' || !str_contains($filename, '.')) {
                $ext = $this->extensionFromMime($mime);
                $base = $filename !== '' ? $filename : 'attachment';
                $filename = $base . ($ext ? ".{$ext}" : '');
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'flow_');
            file_put_contents($tempPath, $response->body());

            return new UploadedFile($tempPath, $filename, $mime, null, true);
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Failed to download attachment', [
                'url' => $url,
                'error' => $th->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Guess a file extension from a MIME type for common attachment formats.
     */
    protected function extensionFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg', 'audio/opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            default => null,
        };
    }
}
