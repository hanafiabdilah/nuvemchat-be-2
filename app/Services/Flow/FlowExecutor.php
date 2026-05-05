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

                // Move to next node
                $this->moveToNextNode($flowState, $node);
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
     * Move to the next node in the flow
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
            return;
        }

        // For now, we only handle Message and Start nodes
        // Response and other interactive nodes will be implemented later
        Log::info('FlowExecutor: Flow is active but current node does not expect input', [
            'node_type' => $currentNode->type->value,
        ]);
    }
}
