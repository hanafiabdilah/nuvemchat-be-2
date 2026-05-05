<?php

namespace App\Services\Flow;

use App\Enums\Flow\NodeType;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Services\Message\MessageService;
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
        ]);

        // Execute from start node
        $this->executeFromNode($flowState, $startNode);
    }

    /**
     * Execute flow from a specific node
     */
    protected function executeFromNode(FlowState $flowState, FlowNode $node): void
    {
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

            // Prepare message data
            $messageData = [
                'message' => $data['body'] ?? '',
            ];

            // Add attachment if present
            if (isset($data['attachment']) && $data['attachment']) {
                $messageData['attachment'] = $data['attachment'];
                $messageData['message_type'] = $data['message_type'] ?? 'text';
            }

            // Send the message
            $message = $this->messageService->sendMessage($conversation, $messageData);

            if ($message) {
                Log::info('FlowExecutor: Message sent', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                ]);

                broadcast(new MessageReceived($message));

                // Move to next node WITHOUT executing it (wait for user response)
                $this->moveToNextNodeWithoutExecute($flowState, $node);
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
     * Move to the next node in the flow and execute it
     */
    protected function moveToNextNode(FlowState $flowState, FlowNode $currentNode): void
    {
        // Find the next node via edge
        $edge = $currentNode->outgoingEdges()->first();

        if (!$edge) {
            // No next node, flow ends
            Log::info('FlowExecutor: Flow ended (no next node)', [
                'flow_state_id' => $flowState->id,
                'current_node_id' => $currentNode->id,
            ]);

            // Delete the flow state to indicate completion
            $flowState->delete();
            return;
        }

        // Load the next node
        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            Log::error('FlowExecutor: Next node not found', [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
            ]);
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
            Log::info('FlowExecutor: Flow ended (no next node)', [
                'flow_state_id' => $flowState->id,
                'current_node_id' => $currentNode->id,
            ]);

            // Delete the flow state to indicate completion
            $flowState->delete();
            return;
        }

        // Load the next node
        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            Log::error('FlowExecutor: Next node not found', [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
            ]);

            // Delete flow state if next node is missing
            $flowState->delete();
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
     * Resume a flow after receiving user input
     */
    public function resumeFlow(Conversation $conversation, string $userInput): void
    {
        $flowState = FlowState::where('conversation_id', $conversation->id)->first();

        if (!$flowState) {
            return; // No active flow
        }

        $currentNode = $flowState->currentNode;

        if (!$currentNode) {
            Log::error('FlowExecutor: Current node not found', [
                'flow_state_id' => $flowState->id,
            ]);

            // Delete orphaned flow state
            $flowState->delete();
            return;
        }

        Log::info('FlowExecutor: Resuming flow from user input', [
            'conversation_id' => $conversation->id,
            'current_node_id' => $currentNode->id,
            'current_node_type' => $currentNode->type->value,
            'user_input' => substr($userInput, 0, 50), // Log first 50 chars only
        ]);

        // Execute the current node (which was set but not executed)
        $this->executeFromNode($flowState, $currentNode);
    }
}
