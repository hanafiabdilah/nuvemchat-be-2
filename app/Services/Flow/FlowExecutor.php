<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationHandoff;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Exceptions\Billing\AiRunQuotaExceededException;
use App\Jobs\RunAiAgentTurn;
use App\Jobs\RunFlowMessageNode;
use App\Jobs\RunFlowResponseTimeout;
use App\Models\AiHubAgent;
use App\Models\AiHubRun;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\User;
use App\Observers\ConversationObserver;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiAttachments;
use App\Services\AiAgentHub\AiDeliveryPolicy;
use App\Services\AiAgentHub\AiFirstMessage;
use App\Services\AiAgentHub\AiTranscription;
use App\Services\AiAgentHub\AiTranscripts;
use App\Services\AiAgentHub\AiVoiceReply;
use App\Services\BusinessHours;
use App\Services\Conversation\SystemMessage;
use App\Services\Live\LiveActivity;
use App\Services\Message\MessageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlowExecutor
{
    /**
     * Safety-net cap on AI agent turns within a single AIAgent node before
     * forcing handoff. Prevents runaway loops if the hub's handoff signal
     * never fires.
     */
    protected const AI_MAX_TURNS = 20;

    /**
     * Most incoming messages folded into a single AI turn.
     *
     * A burst is the normal case, not the exception — turns wait out the
     * customer's typing (see scheduleAIAgentTurn) precisely so the several
     * messages one thought was split across arrive together. Past this many,
     * the newest survive: the tail of a long burst is what the answer is
     * actually about.
     */
    protected const AI_MAX_INPUT_MESSAGES = 10;

    /**
     * How long an AI turn will wait for an image that is still downloading
     * before answering without it.
     *
     * Media is fetched off the queue (DownloadInboundMedia), so at the moment
     * a webhook resumes the flow the file is almost never on disk yet —
     * without a pause the agent would answer every screenshot blind. The job
     * re-enters the turn the instant the bytes land, so in practice the wait
     * is a second or two; this ceiling only covers a download that died
     * without ever reaching its `failed()` handler.
     */
    protected const AI_MEDIA_WAIT_SECONDS = 300;

    /**
     * How long one conversation's AI turn holds the door shut behind it.
     *
     * Turns are queued, so two of them can reach a conversation at once — the
     * debounced burst and a message that landed while the hub was answering.
     * Generous on purpose: the lock expiring early is what would let the same
     * messages be answered twice, which is the bug this whole path exists to
     * prevent.
     */
    protected const AI_TURN_LOCK_SECONDS = 300;

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
        // Automation flows are 1:1 only — never run in group conversations.
        if ($conversation->isGroup()) {
            return;
        }

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

        // Every node passes through here, so one emit covers all ten types.
        // Most of them finish in under a millisecond and are superseded almost
        // immediately — the panel's status line barely shows them. They are
        // broadcast anyway because the client keeps a trail of the last few,
        // and a Condition that silently took the wrong branch is exactly the
        // thing that trail exists to make visible.
        LiveActivity::flowNode($conversation, $node);

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

            case NodeType::Interactive:
                $this->executeInteractiveNode($flowState, $node);
                break;

            case NodeType::Status:
                $this->executeStatusNode($flowState, $node);
                break;

            case NodeType::Action:
                $this->executeActionNode($flowState, $node);
                break;

            default:
                Log::warning('FlowExecutor: Unsupported node type', [
                    'node_type' => $node->type->value,
                ]);
                break;
        }
    }

    /**
     * Execute a Message node — send its bubbles, in order, then move on.
     *
     * A node holds a list now (see MessageNodes), and every bubble may carry a
     * pause before it. With no pauses anywhere the whole thing runs inline, the
     * way a single message always did. With one, the sequence moves to the
     * queue: the pause used to be a sleep() inside the webhook request that
     * delivered the customer's message, and a webhook that sleeps is a webhook
     * the channel retries.
     */
    protected function executeMessageNode(FlowState $flowState, FlowNode $node): void
    {
        $conversation = $flowState->conversation;
        $items = MessageNodes::items($node->data ?? []);

        if ($items === []) {
            // Nothing authored yet — the same treatment an unconfigured
            // interactive or action node gets: step over it rather than stall.
            Log::info('FlowExecutor: Message node has nothing to send, skipping', [
                'node_id' => $node->id,
                'conversation_id' => $conversation->id,
            ]);

            $this->finishMessageNode($flowState, $node);
            return;
        }

        try {
            if ($this->messageChainInFlight($flowState, $node)) {
                // A queued sequence already owns this node. It will finish and
                // move the flow on; re-entering here would send it all again.
                Log::info('FlowExecutor: Message sequence already running, skipping', [
                    'node_id' => $node->id,
                    'conversation_id' => $conversation->id,
                ]);
                return;
            }

            if (!MessageNodes::hasDelay($items)) {
                foreach ($items as $index => $item) {
                    $this->sendMessageItem($flowState, $node, $item, $index);
                }

                $this->finishMessageNode($flowState, $node);
                return;
            }

            $this->startMessageChain($flowState, $node, $items);
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing message node', [
                'node_id' => $node->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Send one bubble of a Message node.
     *
     * A failed send is logged and swallowed: the rest of the sequence is still
     * worth sending, and the alternative — throwing — would let a retry deliver
     * the bubbles before it a second time.
     *
     * @param  array<string, mixed>  $item
     */
    protected function sendMessageItem(FlowState $flowState, FlowNode $node, array $item, int $index): void
    {
        $conversation = $flowState->conversation;

        try {
            $message = $this->sendByMessageType($conversation, $item, $flowState);

            if (!$message) {
                Log::error('FlowExecutor: Failed to send message', [
                    'node_id' => $node->id,
                    'index' => $index,
                    'conversation_id' => $conversation->id,
                ]);
                return;
            }

            Log::info('FlowExecutor: Message sent', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'node_id' => $node->id,
                'index' => $index,
            ]);

            broadcast(new MessageReceived($message));
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error sending message node bubble', [
                'node_id' => $node->id,
                'index' => $index,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Every bubble is out — hand the flow to whatever comes next.
     *
     * `wait_for_reply` is about the node as a whole, not any one bubble: the
     * flow parks on the following node and the customer's next message wakes it.
     */
    protected function finishMessageNode(FlowState $flowState, FlowNode $node): void
    {
        $waitForReply = (($node->data ?? [])['wait_for_reply'] ?? true) !== false;

        if ($waitForReply) {
            $this->moveToNextNodeWithoutExecute($flowState, $node);
            return;
        }

        $this->moveToNextNode($flowState, $node);
    }

    /**
     * Hand a delayed sequence to the queue, starting with the first bubble.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function startMessageChain(FlowState $flowState, FlowNode $node, array $items): void
    {
        $token = (string) Str::uuid();
        $stateData = $flowState->state_data ?? [];

        // The expiry is a floor under a chain whose worker died mid-sequence:
        // without it the node would stay marked "busy" forever and never send
        // again, which is the one failure a customer cannot recover from by
        // writing back.
        $stateData[$this->messageChainKey($node->id)] = [
            'token' => $token,
            'expires_at' => now()->addSeconds(MessageNodes::totalDelay($items) + 300)->timestamp,
        ];

        $flowState->update(['state_data' => $stateData]);

        $firstDelay = (int) ($items[0]['delay'] ?? 0);

        RunFlowMessageNode::dispatch($flowState->id, $node->id, 0, $token)
            ->delay(now()->addSeconds($firstDelay));

        LiveActivity::flowDelay($flowState->conversation, $node, $firstDelay, 0, count($items));

        Log::info('FlowExecutor: Message sequence queued', [
            'node_id' => $node->id,
            'conversation_id' => $flowState->conversation_id,
            'items' => count($items),
        ]);
    }

    /**
     * Send one queued bubble and either queue the next or move the flow on.
     *
     * Called by RunFlowMessageNode. Every early return is a chain that no
     * longer owns the node: a newer one took over, an agent took the
     * conversation, or the flow left this node by some other path.
     */
    public function runScheduledMessageItem(int $flowStateId, int $nodeId, int $index, string $token): void
    {
        $flowState = FlowState::find($flowStateId);

        if (!$flowState || $this->messageChainToken($flowState, $nodeId) !== $token) {
            return;
        }

        $node = FlowNode::find($nodeId);
        $conversation = $flowState->conversation;

        $stillOurs = $flowState->status === FlowStateStatus::Running
            && $flowState->current_node_id === $nodeId
            && $node
            && $node->type === NodeType::Message
            && $conversation
            && in_array($conversation->status, ConversationStatus::flowEligible(), true);

        if (!$stillOurs) {
            // Ours to clear: nobody else holds this token, and leaving it set
            // would block the node if the flow ever came back round to it.
            $this->clearMessageChain($flowState, $nodeId);
            return;
        }

        $items = MessageNodes::items($node->data ?? []);
        $item = $items[$index] ?? null;

        if ($item === null) {
            $this->clearMessageChain($flowState, $nodeId);
            $this->finishMessageNode($flowState, $node);
            return;
        }

        $this->sendMessageItem($flowState, $node, $item, $index);

        $next = $items[$index + 1] ?? null;

        if ($next === null) {
            $this->clearMessageChain($flowState, $nodeId);
            $this->finishMessageNode($flowState, $node);
            return;
        }

        $nextDelay = (int) ($next['delay'] ?? 0);

        RunFlowMessageNode::dispatch($flowStateId, $nodeId, $index + 1, $token)
            ->delay(now()->addSeconds($nextDelay));

        LiveActivity::flowDelay($conversation, $node, $nextDelay, $index + 1, count($items));
    }

    protected function messageChainKey(int $nodeId): string
    {
        return "_message_chain_{$nodeId}";
    }

    /**
     * The token of the sequence currently owning this node, if any is still
     * within its expiry.
     */
    protected function messageChainToken(FlowState $flowState, int $nodeId): ?string
    {
        $chain = $flowState->state_data[$this->messageChainKey($nodeId)] ?? null;

        if (!is_array($chain)) {
            return null;
        }

        if ((int) ($chain['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        $token = $chain['token'] ?? null;

        return is_string($token) ? $token : null;
    }

    protected function messageChainInFlight(FlowState $flowState, FlowNode $node): bool
    {
        return $this->messageChainToken($flowState, $node->id) !== null;
    }

    protected function clearMessageChain(FlowState $flowState, int $nodeId): void
    {
        $stateData = $flowState->state_data ?? [];

        if (!array_key_exists($this->messageChainKey($nodeId), $stateData)) {
            return;
        }

        unset($stateData[$this->messageChainKey($nodeId)]);
        $flowState->update(['state_data' => $stateData]);
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

                // Start the clock on the silence, if the author set one.
                $this->armResponseTimeout($flowState, $node);

                // The one phase that can last an afternoon, so it is also the
                // one worth naming precisely: the panel shows which variable
                // the flow is holding out for, and how long is left on it.
                LiveActivity::flowAwaiting(
                    $conversation,
                    $node,
                    ResponseNodes::timeoutSeconds($data ?? []),
                    ['variable_key' => $data['variable_key'] ?? null],
                );

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
     * Execute an Interactive node — send WhatsApp reply buttons, a list menu or
     * a media carousel and WAIT, exactly like a Response node. Each option is
     * its own outgoing branch, so the customer's pick decides which edge the
     * flow takes; that happens in handleInteractiveNodeInput() once the reply
     * arrives.
     *
     * A link-out carousel is the one shape with nothing to wait for: its cards
     * open a browser rather than reply, so the flow sends and carries on.
     */
    protected function executeInteractiveNode(FlowState $flowState, FlowNode $node): void
    {
        $conversation = $flowState->conversation;
        $data = $this->interpolateInteractiveData($node->data ?? [], $flowState);
        $options = InteractiveNodes::options($data);

        if (!InteractiveNodes::isSendable($data)) {
            Log::warning('FlowExecutor: Interactive node is incomplete, skipping', [
                'node_id' => $node->id,
                'conversation_id' => $conversation->id,
                'interactive_type' => InteractiveNodes::type($data),
            ]);

            // Nothing to ask, so nothing to branch on: fall through the first
            // edge rather than stranding the conversation on a silent node.
            $this->moveToNextNode($flowState, $node);
            return;
        }

        try {
            $isWhatsappOfficial = $conversation->connection->channel === InteractiveNodes::CHANNEL;

            $message = $isWhatsappOfficial
                ? $this->messageService->sendInteractive($conversation, InteractiveNodes::sendPayload($data))
                : $this->sendInteractiveAsPlainText($conversation, $data, $options);

            if (!$message) {
                Log::error('FlowExecutor: Failed to send interactive message', [
                    'node_id' => $node->id,
                    'conversation_id' => $conversation->id,
                ]);
                return;
            }

            $message->update(['sent_by_flow_id' => $flowState->flow_id]);
            broadcast(new MessageReceived($message));

            Log::info('FlowExecutor: Interactive message sent', [
                'node_id' => $node->id,
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'options' => count($options),
                'as_plain_text' => !$isWhatsappOfficial,
            ]);

            if (!InteractiveNodes::awaitsReply($data)) {
                $this->moveToNextNode($flowState, $node);
                return;
            }

            // Mark the prompt as sent and stay put — the next inbound message
            // is the answer, routed back here through resumeFlow().
            $stateData = $flowState->state_data ?? [];
            $stateData["_interactive_sent_{$node->id}"] = true;
            $flowState->update(['state_data' => $stateData]);

            // No timeout branch exists on this node, so there is no clock to
            // show — only that the flow is holding for a tap, and on how many
            // options.
            LiveActivity::flowAwaiting($conversation, $node, null, [
                'options' => count($options),
            ]);
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing interactive node', [
                'node_id' => $node->id,
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Route the customer's selection to the matching branch.
     *
     * The tap arrives as an inbound `interactive` message whose reply id is the
     * option id we sent; typed answers are matched by title or by position.
     * An answer that matches nothing leaves the flow on this node, so the
     * customer can simply tap again.
     */
    protected function handleInteractiveNodeInput(FlowState $flowState, FlowNode $node, string $userInput): void
    {
        // Interpolated the same way as when it was sent, so a title built from
        // a variable still matches what the customer actually saw.
        $data = $this->interpolateInteractiveData($node->data ?? [], $flowState);
        $replyId = $this->latestInteractiveReplyId($flowState->conversation);
        $optionId = InteractiveNodes::matchOption($data, $replyId, $userInput);

        if ($optionId === null) {
            Log::info('FlowExecutor: Interactive reply matched no option, waiting for another', [
                'node_id' => $node->id,
                'conversation_id' => $flowState->conversation_id,
                'reply_id' => $replyId,
                'input' => mb_substr($userInput, 0, 50),
            ]);
            return;
        }

        $stateData = $flowState->state_data ?? [];
        unset($stateData["_interactive_sent_{$node->id}"]);
        $flowState->update(['state_data' => $stateData]);

        Log::info('FlowExecutor: Interactive option selected', [
            'node_id' => $node->id,
            'option_id' => $optionId,
            'conversation_id' => $flowState->conversation_id,
        ]);

        $this->moveToNextNodeByBranch($flowState, $node, $optionId);
    }

    /**
     * The reply id from the conversation's latest inbound interactive message —
     * WhatsApp Cloud stores the raw webhook entry in `meta`, and the tapped
     * button / row id lives inside it.
     */
    protected function latestInteractiveReplyId(Conversation $conversation): ?string
    {
        $meta = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Incoming)
            ->latest('id')
            ->value('meta');

        $interactive = $meta['changes'][0]['value']['messages'][0]['interactive'] ?? null;

        return InteractiveNodes::replyFromWebhook(is_array($interactive) ? $interactive : null)['id'] ?? null;
    }

    /**
     * Fallback for a flow whose connection is not WhatsApp Official — which
     * validation prevents, but a connection can be re-pointed after the fact.
     * The options go out as a numbered list so replying "2" still picks branch 2.
     */
    protected function sendInteractiveAsPlainText(Conversation $conversation, array $data, array $options): ?Message
    {
        Log::warning('FlowExecutor: Interactive node on a non-WhatsApp-Official channel, sending plain text', [
            'conversation_id' => $conversation->id,
            'channel' => $conversation->connection->channel->value,
        ]);

        $isCarousel = InteractiveNodes::type($data) === 'carousel';

        $lines = array_filter([
            $isCarousel ? '' : trim((string) ($data['header'] ?? '')),
            trim((string) ($data['body'] ?? '')),
        ]);

        // A carousel has no plain-text equivalent, so each card is spelled out:
        // its caption, then wherever its button would have taken the customer.
        if ($isCarousel) {
            foreach (InteractiveNodes::cards($data) as $card) {
                $lines[] = '— ' . ($card['body'] !== '' ? $card['body'] : $card['header_url']);

                if (($card['button_url'] ?? '') !== '') {
                    $lines[] = '  ' . $card['button_label'] . ': ' . $card['button_url'];
                }
            }

            if ($options !== []) {
                $lines[] = '';
            }
        }

        foreach ($options as $i => $option) {
            $lines[] = ($i + 1) . '. ' . $option['title'];
        }

        if (!$isCarousel && $footer = trim((string) ($data['footer'] ?? ''))) {
            $lines[] = $footer;
        }

        return $this->messageService->sendMessage($conversation, [
            'message' => implode("\n", $lines),
        ]);
    }

    /**
     * Resolve {{variable}} tokens in every customer-visible string of an
     * interactive node — the texts, the option titles and their descriptions,
     * and a carousel's per-card captions, links and buttons.
     */
    protected function interpolateInteractiveData(array $data, FlowState $flowState): array
    {
        foreach (['header', 'body', 'footer', 'button_label'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = $this->interpolateVariables($data[$key], $flowState);
            }
        }

        foreach ((array) ($data['cards'] ?? []) as $ci => $card) {
            foreach (['header_url', 'body', 'button_label', 'button_url'] as $key) {
                if (isset($card[$key]) && is_string($card[$key])) {
                    $data['cards'][$ci][$key] = $this->interpolateVariables($card[$key], $flowState);
                }
            }

            foreach ((array) ($card['buttons'] ?? []) as $bi => $button) {
                if (isset($button['title']) && is_string($button['title'])) {
                    $data['cards'][$ci]['buttons'][$bi]['title'] = $this->interpolateVariables($button['title'], $flowState);
                }
            }
        }

        foreach ((array) ($data['buttons'] ?? []) as $i => $button) {
            if (isset($button['title']) && is_string($button['title'])) {
                $data['buttons'][$i]['title'] = $this->interpolateVariables($button['title'], $flowState);
            }
        }

        foreach ((array) ($data['sections'] ?? []) as $si => $section) {
            if (isset($section['title']) && is_string($section['title'])) {
                $data['sections'][$si]['title'] = $this->interpolateVariables($section['title'], $flowState);
            }

            foreach ((array) ($section['rows'] ?? []) as $ri => $row) {
                foreach (['title', 'description'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key])) {
                        $data['sections'][$si]['rows'][$ri][$key] = $this->interpolateVariables($row[$key], $flowState);
                    }
                }
            }
        }

        return $data;
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
     * Execute a Status node — close the conversation.
     *
     * Terminal by design, and the node is drawn without an output handle to say
     * so. Resolving is what ends a conversation, so a node wired after this one
     * would be a promise the engine cannot keep: executeFromNode refuses to run
     * in a conversation that is no longer flow-eligible, and Resolved is not.
     */
    protected function executeStatusNode(FlowState $flowState, FlowNode $node): void
    {
        $value = $node->data['value'] ?? null;

        // Only Resolved is authorable (see NodeType::data). Anything else is a
        // node from before that was settled, and guessing which status its
        // author meant is worse than leaving the conversation alone.
        if ($value !== ConversationStatus::Resolved->value) {
            Log::warning('FlowExecutor: Status node has no status to set, skipping', [
                'node_id' => $node->id,
                'value' => $value,
            ]);

            $this->moveToNextNode($flowState, $node);

            return;
        }

        $conversation = $flowState->conversation;

        if ($conversation->status === ConversationStatus::Resolved) {
            Log::info('FlowExecutor: Status node reached an already-resolved conversation', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        // No actor, because nobody clicked anything: ConversationObserver turns
        // this save into the thread's own "Status: pending → resolved" note, and
        // the version of that sentence without a name is the honest one.
        $conversation->markResolved();

        // Reaching the node the author wired as the end is not the same event as
        // a human interrupting the bot, which is what the observer's stopFlow
        // records — hence Completed, written after the save so it is the state
        // that survives. It is also the only thing that ends a flow whose
        // conversation was AiHandling: that stopFlow only fires from Pending.
        $flowState->update([
            'status' => FlowStateStatus::Completed,
            'completed_at' => now(),
        ]);

        broadcast(new ConversationUpdated($conversation->fresh()));

        // Terminal node: nothing follows it, so nothing will supersede the
        // "executing" line this node emitted on its way in. Clear it here —
        // stopFlow() is not on this path, the flow completed rather than
        // being interrupted.
        LiveActivity::idle($conversation);

        Log::info('FlowExecutor: Conversation resolved by status node', [
            'conversation_id' => $conversation->id,
            'node_id' => $node->id,
            'flow_state_id' => $flowState->id,
        ]);
    }

    /**
     * Execute an Action node — do something to the conversation itself.
     *
     * The three actions differ in who ends up holding the conversation, and
     * that is what decides whether the flow continues: assigning names a
     * person, transferring names the queue, and a note names nobody.
     */
    protected function executeActionNode(FlowState $flowState, FlowNode $node): void
    {
        $data = $node->data ?? [];
        $type = $data['type'] ?? null;
        $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];

        try {
            $handedOver = match ($type) {
                ActionNodes::ASSIGN_AGENT => $this->assignConversationToAgent($flowState, $node, $parameters),
                ActionNodes::TRANSFER_HUMAN => $this->transferConversationToQueue($flowState),
                ActionNodes::INTERNAL_NOTE => $this->writeInternalNote($flowState, $node, $parameters),
                default => $this->skipUnconfiguredAction($node, $type),
            };

            if ($handedOver) {
                return;
            }
        } catch (\Throwable $th) {
            Log::error('FlowExecutor: Error executing action node', [
                'node_id' => $node->id,
                'action' => $type,
                'error' => $th->getMessage(),
            ]);
        }

        // Nobody took the conversation — a note, a node its author never
        // finished, or one that threw. The flow carries on for the same reason
        // the tagging node does: a side effect failing is not a reason to
        // strand the customer halfway through.
        $this->moveToNextNode($flowState, $node);
    }

    /**
     * Assign the conversation to one named agent.
     *
     * Returns true once somebody owns the conversation — the agent on the node,
     * or the queue when that agent cannot take it. False means the node was
     * never configured and the flow should carry on past it.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function assignConversationToAgent(FlowState $flowState, FlowNode $node, array $parameters): bool
    {
        $agentId = (int) ($parameters['agent_id'] ?? 0);

        if ($agentId <= 0) {
            // Not a runtime condition — the node is half-finished, and the same
            // rule applies as to an interactive node with no options: skip it
            // rather than act on a blank.
            Log::warning('FlowExecutor: Action node has no agent to assign to, skipping', [
                'node_id' => $node->id,
            ]);

            return false;
        }

        $conversation = $flowState->conversation;
        $connection = $conversation->connection;

        $agent = User::where('tenant_id', $connection?->tenant_id)->find($agentId);

        // Connection access is the product's real boundary, not a UI filter:
        // revoking it is meant to close the threads an agent still holds, so
        // handing them a new one here would walk straight around it.
        if (! $agent || ! $agent->canAccessConnection($conversation->connection_id)) {
            Log::warning('FlowExecutor: Assign-agent target unavailable, handing to the queue', [
                'node_id' => $node->id,
                'agent_id' => $agentId,
                'conversation_id' => $conversation->id,
                'reason' => $agent ? 'no_connection_access' : 'agent_missing',
            ]);

            $this->transferToHuman($flowState, ActionNodes::REASON_AGENT_UNAVAILABLE);

            return true;
        }

        // Presence is the one condition the author gets to overrule, because it
        // is about the shift rather than about the account. Assigning to an
        // empty chair leaves a customer waiting on somebody who is not coming,
        // while the queue they would land in instead is watched by everyone —
        // the same reasoning that gates LastAgentRouter.
        if (ActionNodes::unavailableMode($parameters) === ActionNodes::UNAVAILABLE_QUEUE && ! $agent->isOnline()) {
            Log::info('FlowExecutor: Assign-agent target is offline, handing to the queue', [
                'node_id' => $node->id,
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
            ]);

            $this->transferToHuman($flowState, ActionNodes::REASON_AGENT_OFFLINE);

            return true;
        }

        // The status note is suppressed because the assignment note below says
        // strictly more: it names the agent as well as implying pending →
        // active. Same call ConversationController::applyAccept makes.
        ConversationObserver::withoutStatusNote(function () use ($conversation, $agent) {
            $conversation->forceFill([
                'user_id' => $agent->id,
                'status' => ConversationStatus::Active,
                'needs_human' => false,
            ])->save();
        });

        // Belt and braces: the observer stops the flow on pending → active but
        // not on ai_handling → active, and the bot has to fall silent either way.
        $this->stopFlow($conversation);

        // Written before the broadcast so the inbox row lands on its final
        // preview instead of flickering through the previous message.
        SystemMessage::info(
            $conversation,
            "The flow assigned this conversation to {$agent->name}.",
            ActionNodes::INFO_ASSIGNED_BY_FLOW,
            ['to' => $agent->name],
        );

        broadcast(new ConversationUpdated($conversation->fresh()));

        Log::info('FlowExecutor: Conversation assigned to an agent by the flow', [
            'conversation_id' => $conversation->id,
            'node_id' => $node->id,
            'agent_id' => $agent->id,
        ]);

        return true;
    }

    /**
     * Put the conversation in the unassigned human queue and ring for an agent.
     *
     * Reuses the AI node's handoff so the two are the same event: needs_human,
     * back to Pending, the bot silenced, and ConversationHandoff broadcast so
     * every open dashboard toasts and the "needs an agent" badge appears. Before
     * this action existed that was reachable only through an AI Agent node, so a
     * flow with no AI in it had no way at all to ask for a person.
     */
    protected function transferConversationToQueue(FlowState $flowState): bool
    {
        $this->transferToHuman($flowState, ActionNodes::REASON_REQUESTED);

        return true;
    }

    /**
     * Write a line into the thread that the customer never sees.
     *
     * Stored with no code, so the SPA renders the body as-is: the sentence was
     * written by the tenant, in the tenant's own language, and a translation
     * table has nothing to add to it.
     *
     * Always returns false — a note hands the conversation to nobody, so it is
     * the one action the flow continues past.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function writeInternalNote(FlowState $flowState, FlowNode $node, array $parameters): bool
    {
        $text = trim($this->interpolateVariables((string) ($parameters['note'] ?? ''), $flowState));

        if ($text === '') {
            Log::warning('FlowExecutor: Action node has an empty internal note, skipping', [
                'node_id' => $node->id,
            ]);

            return false;
        }

        SystemMessage::info($flowState->conversation, $text);

        Log::info('FlowExecutor: Internal note written by flow', [
            'conversation_id' => $flowState->conversation_id,
            'node_id' => $node->id,
        ]);

        return false;
    }

    /**
     * An action node whose author never picked an action. Skipped, not failed:
     * an unfinished node should be invisible to the customer, not a dead end.
     */
    protected function skipUnconfiguredAction(FlowNode $node, mixed $type): bool
    {
        Log::warning('FlowExecutor: Action node has no action selected, skipping', [
            'node_id' => $node->id,
            'action' => $type,
        ]);

        return false;
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

            // Announced before the call, not after: a third-party endpoint that
            // hangs for its full timeout is precisely the pause this is here to
            // explain, and by the time the response lands there is nothing left
            // to wait for.
            LiveActivity::flowHttp($flowState->conversation, $node, $method, $url);

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

        $this->followEdge($flowState, $edge, ['branch' => $branch]);
    }

    /**
     * Walk one edge: point the flow state at its target and run it.
     *
     * Every "move to the next node" in this class ends here, whichever way it
     * chose the edge — so a dangling target is reported the same way once,
     * rather than in five places that could drift apart.
     *
     * @param  array<string, mixed>  $logContext
     */
    protected function followEdge(FlowState $flowState, FlowEdge $edge, array $logContext = []): void
    {
        $nextNode = FlowNode::find($edge->target_node_id);

        if (!$nextNode) {
            $flowState->update([
                'status' => FlowStateStatus::Failed,
                'completed_at' => now(),
            ]);

            Log::error('FlowExecutor: Next node not found (flow state preserved)', $logContext + [
                'edge_id' => $edge->id,
                'target_node_id' => $edge->target_node_id,
                'flow_state_id' => $flowState->id,
                'status' => 'failed',
            ]);
            return;
        }

        $flowState->update(['current_node_id' => $nextNode->id]);

        Log::info('FlowExecutor: Moved to next node', $logContext + [
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
            // Use the full remainder (collapsing legacy repeated "variable."
            // prefixes) so "variable.x" and "variable.variable.x" both read
            // state_data['x'].
            'variable' => $flowState->state_data[preg_replace('/^(?:variable\.)+/', '', $field)] ?? null,
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

        $this->followEdge($flowState, $edge, ['condition_result' => $conditionValue]);
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

        $this->followEdge($flowState, $edge);
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

            // Whatever the flow was announcing, it is no longer true. Covers
            // the handoff path too: transferToHuman() ends up here.
            LiveActivity::idle($conversation);

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
        // Automation flows are 1:1 only — never run in group conversations.
        if ($conversation->isGroup()) {
            return;
        }

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

        // Special handling for AIAgent nodes - feed user input to the agent.
        //
        // $userInput is deliberately not forwarded: the turn is assembled from
        // the stored messages instead. A caption reaches the agent as a string
        // either way, but only the row it was stored on knows there is an
        // image underneath it — and only the stored watermark can tell that
        // something arrived while an earlier turn was held back.
        //
        // Nor is the turn run here. It is armed, and this message re-arms it —
        // see scheduleAIAgentTurn(). Answering each message as it lands is what
        // made a customer typing in bursts get one reply per burst.
        if ($currentNode->type === NodeType::AIAgent) {
            $this->scheduleAIAgentTurn($flowState, $currentNode);
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

        // Interactive nodes wait the same way: send once, then treat the next
        // inbound message as the customer's pick.
        if ($currentNode->type === NodeType::Interactive) {
            $stateData = $flowState->state_data ?? [];

            if (!isset($stateData["_interactive_sent_{$currentNode->id}"])) {
                $this->executeInteractiveNode($flowState, $currentNode);
                return;
            }

            $this->handleInteractiveNodeInput($flowState, $currentNode, $userInput);
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
        // Legacy nodes saved the key as "variable.x" — templates and Condition
        // lookups resolve {{variable.x}} to state_data['x'], so store bare.
        if (is_string($variableKey)) {
            $variableKey = preg_replace('/^(?:variable\.)+/', '', $variableKey) ?: null;
        }
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

            // Stay on current Response node - don't move. The customer did
            // answer, though — they answered wrongly — so the silence clock
            // starts over rather than running out on someone who is still here.
            $this->armResponseTimeout($flowState, $node);
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

        // Clear the response sent flag since we're moving to next node.
        // Disarming the timeout matters as much: the job is already queued and
        // would otherwise wake up to take the no-reply branch of a question
        // that has been answered.
        unset($stateData["_response_sent_{$node->id}"], $stateData[$this->responseTimeoutKey($node->id)]);

        $flowState->update([
            'state_data' => $stateData,
        ]);

        // Move to next node and execute it
        $this->moveToNextNodeAfterReply($flowState, $node);
    }

    /**
     * Arm (or re-arm) a Response node's no-reply timer.
     *
     * A fresh token each time, so a job queued for an earlier arming finds a
     * stranger's token and steps aside — the same debounce the AI turn uses,
     * and for the same reason: the queue holds jobs the flow has moved past.
     */
    protected function armResponseTimeout(FlowState $flowState, FlowNode $node): void
    {
        $seconds = ResponseNodes::timeoutSeconds($node->data ?? []);

        if ($seconds <= 0) {
            return;
        }

        $token = (string) Str::uuid();
        $stateData = $flowState->state_data ?? [];
        $stateData[$this->responseTimeoutKey($node->id)] = $token;
        $flowState->update(['state_data' => $stateData]);

        RunFlowResponseTimeout::dispatch($flowState->id, $node->id, $token)
            ->delay(now()->addSeconds($seconds));

        Log::info('FlowExecutor: Response timeout armed', [
            'node_id' => $node->id,
            'conversation_id' => $flowState->conversation_id,
            'timeout_seconds' => $seconds,
        ]);
    }

    protected function responseTimeoutKey(int $nodeId): string
    {
        return "_response_timeout_{$nodeId}";
    }

    /**
     * Nobody answered in time — take the Response node's `timeout` branch.
     *
     * Called by RunFlowResponseTimeout. An unwired timeout branch is not a
     * failure: the node goes on waiting exactly as it did before, which is why
     * the edge is looked up before any state is touched.
     */
    public function runResponseTimeout(int $flowStateId, int $nodeId, string $token): void
    {
        $flowState = FlowState::find($flowStateId);

        if (!$flowState || ($flowState->state_data[$this->responseTimeoutKey($nodeId)] ?? null) !== $token) {
            return;
        }

        if ($flowState->status !== FlowStateStatus::Running || $flowState->current_node_id !== $nodeId) {
            return;
        }

        $node = FlowNode::find($nodeId);

        if (!$node || $node->type !== NodeType::Response) {
            return;
        }

        $conversation = $flowState->conversation;

        if (!$conversation || !in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            // An agent took the conversation while the clock ran. Whatever the
            // author wanted to happen after silence, it was not this.
            return;
        }

        $edge = $node->outgoingEdges()
            ->where('condition_value', ResponseNodes::BRANCH_TIMEOUT)
            ->first();

        if (!$edge) {
            Log::info('FlowExecutor: Response timeout fired with no branch wired, still waiting', [
                'node_id' => $node->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        $stateData = $flowState->state_data ?? [];
        unset($stateData["_response_sent_{$node->id}"], $stateData[$this->responseTimeoutKey($node->id)]);
        $flowState->update(['state_data' => $stateData]);

        Log::info('FlowExecutor: Response timed out, taking the no-reply branch', [
            'node_id' => $node->id,
            'conversation_id' => $conversation->id,
        ]);

        $this->followEdge($flowState, $edge, ['branch' => ResponseNodes::BRANCH_TIMEOUT]);
    }

    /**
     * Move on after the customer answered a Response node.
     *
     * Prefers the node's `replied` handle. Flows saved before the node grew a
     * second output wrote their one edge with no branch value at all, so that
     * is the fallback — an old flow keeps running without being re-wired.
     */
    protected function moveToNextNodeAfterReply(FlowState $flowState, FlowNode $node): void
    {
        $edge = $node->outgoingEdges()
            ->where('condition_value', ResponseNodes::BRANCH_REPLIED)
            ->first()
            ?? $node->outgoingEdges()->whereNull('condition_value')->first();

        if (!$edge) {
            Log::info('FlowExecutor: No edge after response, flow path ends (flow state preserved)', [
                'node_id' => $node->id,
                'flow_state_id' => $flowState->id,
            ]);
            return;
        }

        $this->followEdge($flowState, $edge, ['branch' => ResponseNodes::BRANCH_REPLIED]);
    }

    /**
     * Execute an AIAgent node.
     *
     * The first turn always sends the configured `welcoming_message`. Whether
     * the AI also runs on that first turn depends on what the customer opened
     * with, and AiFirstMessage is what decides: a bare "Halo" gets the welcome
     * and nothing else, because a model handed a greeting and no context
     * answers it awkwardly — but somebody who opens with their actual problem
     * gets both, because making them repeat themselves is the platform asking
     * for something it was already given. Every later turn goes to the AI.
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

        // The whole burst, not just its first line. "oi" and the question that
        // follows it a second later are one opening, and reading only the
        // earlier of the two is how a real question gets filed as a greeting.
        $pendingMessages = Message::where('conversation_id', $flowState->conversation_id)
            ->where('sender_type', SenderType::Incoming)
            ->whereNull('unsend_at')
            ->where('id', '>', $lastProcessedId)
            ->orderBy('id')
            ->limit(self::AI_MAX_INPUT_MESSAGES)
            ->get();

        if ($pendingMessages->isEmpty()) {
            Log::info('FlowExecutor: AIAgent node reached, no pending input — waiting', [
                'node_id' => $node->id,
                'conversation_id' => $flowState->conversation_id,
                'turns' => $stateData[$turnsKey],
            ]);
            return;
        }

        $newestPendingId = (int) $pendingMessages->last()->id;
        $isFirstTurn = ($stateData[$turnsKey] ?? 0) === 0;

        if ($isFirstTurn) {
            $welcomingMessage = trim((string) ($data['welcoming_message'] ?? ''));

            if ($welcomingMessage === '') {
                Log::error('FlowExecutor: AIAgent node missing required welcoming_message, skipping AI on first turn', [
                    'node_id' => $node->id,
                    'conversation_id' => $flowState->conversation_id,
                ]);

                $stateData[$turnsKey] = 1;
                $stateData[$lastProcessedKey] = $newestPendingId;
                $flowState->update(['state_data' => $stateData]);
                return;
            }

            // Off means the node keeps the older behaviour: greet, then wait
            // for the customer to say something a second time. Absent means
            // on — the flows built before this existed are the ones the
            // dropped question was costing.
            $answerNow = ($data['answer_first_message'] ?? true)
                && AiFirstMessage::needsAnswer($pendingMessages);

            Log::info('FlowExecutor: AIAgent first turn', [
                'node_id' => $node->id,
                'conversation_id' => $flowState->conversation_id,
                'message_ids' => $pendingMessages->pluck('id')->all(),
                'answering_now' => $answerNow,
            ]);

            // No watermark when the AI is about to answer: leaving these
            // messages unprocessed is exactly what puts them in the turn.
            $this->sendAIAgentWelcome(
                $flowState,
                $node,
                $welcomingMessage,
                $answerNow ? null : $newestPendingId
            );

            if ($answerNow) {
                // Armed rather than run, so the welcome goes out first and the
                // customer still gets the grouping window they would get on
                // any other turn — the opening burst is the one most likely to
                // still be arriving.
                $this->scheduleAIAgentTurn($flowState, $node);
            }

            return;
        }

        Log::info('FlowExecutor: AIAgent processing pending message on node entry', [
            'node_id' => $node->id,
            'conversation_id' => $flowState->conversation_id,
            'message_id' => $newestPendingId,
        ]);

        $this->scheduleAIAgentTurn($flowState, $node);
    }

    /**
     * Arm the AI turn for this node, or push back the one already armed.
     *
     * This is the whole of the burst handling: the token written here is what
     * the queued job checks itself against when it wakes up, so the only job
     * that gets to run is the one armed by the last message the customer sent.
     * Everything queued behind an earlier message finds a token that is no
     * longer its own and steps aside — and the turn that does run assembles
     * every message since the watermark, so nothing is lost by waiting.
     */
    protected function scheduleAIAgentTurn(FlowState $flowState, FlowNode $node): void
    {
        $delay = $this->aiTurnDelaySeconds($node);
        $token = (string) Str::uuid();

        $stateData = $flowState->state_data ?? [];
        $stateData[$this->aiDebounceKey($node->id)] = $token;
        $flowState->update(['state_data' => $stateData]);

        RunAiAgentTurn::dispatch($flowState->id, $node->id, $token)
            ->delay(now()->addSeconds($delay));

        // The debounce window is silence with a reason, and it is the silence
        // agents misread most often: nothing has been sent, nothing is being
        // computed, the bot is simply letting the customer finish. Saying so
        // is what stops somebody taking the thread over mid-turn.
        LiveActivity::aiArmed($flowState->conversation, $node, $delay);

        Log::info('FlowExecutor: AIAgent turn armed', [
            'node_id' => $node->id,
            'conversation_id' => $flowState->conversation_id,
            'delay_seconds' => $delay,
        ]);
    }

    /**
     * Run a turn that was armed earlier, if it is still the one owed.
     *
     * Returns false — and only false — when another turn holds this
     * conversation, which is the caller's cue to come back rather than give
     * up: the messages this one was armed for are still unanswered.
     */
    public function runScheduledAiTurn(int $flowStateId, int $nodeId, string $token): bool
    {
        $flowState = FlowState::find($flowStateId);

        if (!$flowState || $flowState->status !== FlowStateStatus::Running) {
            return true;
        }

        // A later message re-armed the turn. That job owns it now.
        if (($flowState->state_data[$this->aiDebounceKey($nodeId)] ?? null) !== $token) {
            return true;
        }

        $node = $flowState->currentNode;

        if (!$node || $node->id !== $nodeId || $node->type !== NodeType::AIAgent) {
            return true;
        }

        $conversation = $flowState->conversation;

        // Between the arming and now, an agent may have taken the conversation.
        if (!$conversation || !in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            return true;
        }

        $lock = Cache::lock($this->aiTurnLockKey($conversation->id), self::AI_TURN_LOCK_SECONDS);

        if (!$lock->get()) {
            return false;
        }

        try {
            // The turn we queued behind may have moved the watermark, sent the
            // reply, or handed the conversation to a human while we waited.
            $flowState->refresh();

            $stateData = $flowState->state_data ?? [];

            if ($flowState->status !== FlowStateStatus::Running
                || $flowState->current_node_id !== $nodeId
                || ($stateData[$this->aiDebounceKey($nodeId)] ?? null) !== $token) {
                return true;
            }

            // Disarmed before the turn runs, not after: from here on, anything
            // that arrives is the next turn's input, and resumeAfterMedia reads
            // this key to tell "a turn is coming" from "a turn is waiting on me".
            unset($stateData[$this->aiDebounceKey($nodeId)]);
            $flowState->update(['state_data' => $stateData]);

            $this->runAIAgentTurn($flowState, $node);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * How long this node waits for the customer to finish typing.
     *
     * The node's own setting wins; the config default covers every flow built
     * before the field existed. Clamped, because the value comes from a form.
     */
    protected function aiTurnDelaySeconds(FlowNode $node): int
    {
        $configured = ($node->data ?? [])['response_delay_seconds'] ?? null;

        $seconds = is_numeric($configured)
            ? (int) $configured
            : (int) config('ai.turn_delay_seconds', 8);

        return max(0, min($seconds, (int) config('ai.max_turn_delay_seconds', 300)));
    }

    protected function aiDebounceKey(int $nodeId): string
    {
        return "_ai_debounce_token_{$nodeId}";
    }

    protected function aiTurnLockKey(int $conversationId): string
    {
        return "ai-turn:{$conversationId}";
    }

    /**
     * Assemble one AI turn out of what the customer has actually sent, and run
     * it: their text, the screenshots that text is about, and the voice notes
     * they sent instead of text.
     *
     * Everything since the last turn goes in, not just the newest message.
     * "Here's the error" followed a second later by the screenshot is one
     * thought split across two messages, and answering only the half that
     * arrived last is how the agent ends up asking for something it was
     * already given.
     *
     * Reached only through runScheduledAiTurn(), which holds the conversation
     * lock for the duration — nothing here guards against a second turn
     * running alongside it.
     */
    protected function runAIAgentTurn(FlowState $flowState, FlowNode $node): void
    {
        $stateData = $flowState->state_data ?? [];
        $watermark = $stateData["_ai_last_processed_message_id_{$node->id}"] ?? null;

        $messages = $this->pendingAiMessages(
            $flowState,
            is_numeric($watermark) ? (int) $watermark : null
        );

        if ($messages->isEmpty()) {
            return;
        }

        if ($this->awaitingMediaDownload($messages)) {
            // Hold the turn. DownloadInboundMedia calls resumeAfterMedia() the
            // moment the file lands (or gives up), and nothing here has been
            // marked processed, so the same messages will be picked up again.
            Log::info('FlowExecutor: AIAgent turn deferred, waiting for media download', [
                'node_id' => $node->id,
                'conversation_id' => $flowState->conversation_id,
                'message_ids' => $messages->pluck('id')->all(),
            ]);

            LiveActivity::aiMedia($flowState->conversation, $node);

            return;
        }

        // Capped newest-first, so the media that survives a burst is the recent
        // one — then flipped back, so what the agent sees is in the order the
        // text below refers to it. The messages travel alongside because a
        // transcription has to find its way back to the voice note it came from.
        $entries = array_reverse(AiAttachments::forMessagesWithSources($messages->reverse()));

        $text = trim($messages->map(function (Message $message) {
            $body = trim((string) $message->body);

            return $body !== '' ? $body : AiAttachments::describe($message);
        })->implode("\n"));

        // "Me responde por áudio" is an instruction about the whole
        // conversation, so it is read before the run and remembered after it.
        $this->rememberVoicePreference($flowState, $node, $messages->pluck('body'));

        $spokenTo = $messages->contains(fn (Message $message) => $message->message_type === MessageType::Audio);

        // The hub round-trip is the longest silence in the whole path, and the
        // only one where something really is happening. Cleared in `finally`
        // so a run that throws does not leave the thread spinning — the ttl
        // would eventually do it, but minutes later.
        LiveActivity::aiThinking($flowState->conversation, $node);

        try {
            $this->handleAIAgentInput(
                $flowState,
                $node,
                $text,
                (int) $messages->last()->id,
                $entries,
                $spokenTo
            );
        } finally {
            LiveActivity::idle($flowState->conversation);
        }

        // Second pass over the same messages, now that their voice notes have
        // been written down: a request made out loud only becomes readable
        // once the hub has answered, and it has to count for the next turn.
        $this->rememberVoicePreference(
            $flowState,
            $node,
            $messages->map(fn (Message $message) => data_get($message->meta, 'transcription.text'))
        );
    }

    /**
     * Record whether the customer has asked to be answered out loud (or asked
     * us to stop), and return what stands now.
     *
     * Sticky, unlike a voice note: the request is about the conversation, not
     * about the message it arrived in. Only an explicit signal moves it, so
     * every ordinary message leaves the setting exactly as it was.
     *
     * @param  Collection<int, string|null>|iterable<string|null>  $texts
     */
    protected function rememberVoicePreference(FlowState $flowState, FlowNode $node, iterable $texts): bool
    {
        $signal = null;

        // Last word wins: "manda áudio... na verdade escreve" is one person
        // changing their mind inside a single burst.
        foreach ($texts as $text) {
            $signal = AiVoiceReply::requestSignal($text) ?? $signal;
        }

        $key = "_ai_voice_{$node->id}";

        // Re-read: the run between the two passes writes state_data of its own,
        // and merging into a stale copy would undo it.
        $flowState->refresh();
        $stateData = $flowState->state_data ?? [];

        if ($signal === null) {
            return (bool) ($stateData[$key] ?? false);
        }

        if ((bool) ($stateData[$key] ?? false) === $signal) {
            return $signal;
        }

        $stateData[$key] = $signal;
        $flowState->update(['state_data' => $stateData]);

        Log::info('FlowExecutor: AIAgent voice preference changed', [
            'node_id' => $node->id,
            'conversation_id' => $flowState->conversation_id,
            'speak' => $signal,
        ]);

        return $signal;
    }

    /**
     * The incoming messages this turn owes an answer to, oldest first.
     *
     * $lastProcessedId is null only when no welcome turn has run — the key is
     * always written once it has, and zero is a real value there: it means the
     * welcome went out alongside an opening the AI is meant to answer, so
     * everything in the conversation is still owed a reply.
     *
     * @return Collection<int, Message>
     */
    protected function pendingAiMessages(FlowState $flowState, ?int $lastProcessedId): Collection
    {
        $query = Message::where('conversation_id', $flowState->conversation_id)
            ->where('sender_type', SenderType::Incoming)
            ->whereNull('unsend_at');

        if ($lastProcessedId === null) {
            // No watermark at all: the defensive path — answer the newest
            // message rather than replaying a history the agent never saw.
            return $query->latest('id')->limit(1)->get();
        }

        return $query->where('id', '>', $lastProcessedId)
            ->orderByDesc('id')
            ->limit(self::AI_MAX_INPUT_MESSAGES)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * True while one of these messages carries media whose bytes are still in
     * flight and recent enough to be worth waiting for.
     *
     * Which media that is, and why, is AiAttachments::awaitingMedia's call —
     * the same rule that decides what would have been attached had it landed.
     */
    protected function awaitingMediaDownload(Collection $messages): bool
    {
        $cutoff = now()->subSeconds(self::AI_MEDIA_WAIT_SECONDS);

        return $messages->contains(fn (Message $message) => AiAttachments::awaitingMedia($message)
            && $message->created_at !== null
            && $message->created_at->gt($cutoff));
    }

    /**
     * Re-enter an AI turn that was held back for this message's download.
     *
     * Called by DownloadInboundMedia once the file is on disk (or once it has
     * given up on it). Every guard here answers the same question — is this
     * still the message the agent is waiting on? — because between the webhook
     * and the queue worker the conversation may have been handed to a human,
     * resolved, or moved on to a turn that already went out without the image.
     */
    public function resumeAfterMedia(Message $message): void
    {
        $conversation = $message->conversation;

        if (!$conversation
            || $conversation->isGroup()
            || $message->sender_type !== SenderType::Incoming) {
            return;
        }

        if (!in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            return;
        }

        $flowState = FlowState::where('conversation_id', $conversation->id)->first();

        if (!$flowState || $flowState->status !== FlowStateStatus::Running) {
            return;
        }

        $node = $flowState->currentNode;

        if (!$node || $node->type !== NodeType::AIAgent) {
            return;
        }

        $stateData = $flowState->state_data ?? [];

        // Turn zero means the node has not sent its welcome yet. Entering it is
        // executeAIAgentNode's job, and if that turn is owed an answer too it
        // arms one of its own — which lands here again, with the count moved.
        if ((int) ($stateData["_ai_turns_{$node->id}"] ?? 0) === 0) {
            return;
        }

        if ($message->id <= (int) ($stateData["_ai_last_processed_message_id_{$node->id}"] ?? 0)) {
            return;
        }

        // A turn is already armed and has not fired yet: the customer is still
        // typing, and that job will pick this file up along with whatever else
        // they send. Running now would answer a half-finished thought and undo
        // the wait it is serving.
        if (($stateData[$this->aiDebounceKey($node->id)] ?? null) !== null) {
            return;
        }

        Log::info('FlowExecutor: media landed, running the AI turn it was holding', [
            'node_id' => $node->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'attachment_status' => $message->attachment_status?->value,
        ]);

        // Straight through the arming path so the conversation lock still
        // applies, but with no wait: the download already spent it.
        $this->dispatchAiTurnNow($flowState, $node);
    }

    /**
     * Arm a turn that is due immediately — the customer has already waited on
     * something other than themselves.
     */
    protected function dispatchAiTurnNow(FlowState $flowState, FlowNode $node): void
    {
        $token = (string) Str::uuid();

        $stateData = $flowState->state_data ?? [];
        $stateData[$this->aiDebounceKey($node->id)] = $token;
        $flowState->update(['state_data' => $stateData]);

        RunAiAgentTurn::dispatch($flowState->id, $node->id, $token);
    }

    /**
     * Send the configured welcoming message and advance the turn counter.
     *
     * $watermark is the id of the last message the welcome is taken to have
     * answered — pass null when the AI is about to answer them itself, which
     * leaves them unprocessed and therefore inside the turn that follows. The
     * key is written either way: its absence is what tells a later turn that
     * no welcome has happened at all.
     */
    protected function sendAIAgentWelcome(FlowState $flowState, FlowNode $node, string $welcomingMessage, ?int $watermark): void
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
        $stateData["_ai_last_processed_message_id_{$node->id}"] = $watermark ?? 0;
        $flowState->update(['state_data' => $stateData]);

        Log::info('FlowExecutor: AIAgent welcoming message sent', [
            'node_id' => $node->id,
            'conversation_id' => $conversation->id,
            'answered_messages_up_to' => $watermark,
        ]);
    }

    /**
     * Handle user input for an AIAgent node: call the hub, relay the reply
     * to the contact, and decide whether to keep looping on this node or
     * hand off to the next node.
     *
     * Callers reach this through runAIAgentTurn(), which is what decides what
     * "the input" is — the text and the images that come with it.
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
    protected function handleAIAgentInput(
        FlowState $flowState,
        FlowNode $node,
        string $userInput,
        ?int $sourceMessageId = null,
        array $attachmentEntries = [],
        bool $customerSpoke = false
    ): void {
        $attachments = array_column($attachmentEntries, 'attachment');

        $data = $node->data;
        $conversation = $flowState->conversation;
        $stateData = $flowState->state_data ?? [];
        $turnsKey = "_ai_turns_{$node->id}";
        $reasonKey = "_ai_handoff_reason_{$node->id}";
        $lastProcessedKey = "_ai_last_processed_message_id_{$node->id}";

        if ($sourceMessageId !== null) {
            $stateData[$lastProcessedKey] = $sourceMessageId;

            // Written before the hub is called, not after it answers. The model
            // takes seconds, and a message that lands in that gap belongs to the
            // next turn — held in memory only, the watermark would still read as
            // unanswered and the same messages would be assembled a second time.
            $flowState->update(['state_data' => $stateData]);
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

        $voice = AiVoiceReply::config($data);

        // Decided from the customer's side, because it has to be: the voice is
        // generated during the run, so asking for it comes before a word of the
        // answer exists. What the answer turns out to say is judged afterwards.
        $decision = AiDeliveryPolicy::decide(
            $voice['mode'],
            $conversation->connection->channel,
            $customerSpoke,
            (bool) ($stateData["_ai_voice_{$node->id}"] ?? false),
            (bool) ($stateData["_ai_last_spoken_{$node->id}"] ?? false),
        );

        $speak = AiDeliveryPolicy::speaks($decision);

        try {
            $run = $this->aiAgentHubService->runAgent(
                $agent,
                $conversation,
                $userInput,
                $flowState->id,
                $node->id,
                attachments: $attachments,
                responseAudio: $speak ? AiVoiceReply::options($voice, $conversation->connection->channel) : [],
                inputAudio: AiTranscription::options($data, $attachments, $conversation->connection->tenant)
            );

            // Before the reply is sent: the transcription belongs to the
            // customer's message, and an agent reading the thread from the top
            // should not find the answer above the question.
            AiTranscripts::store($run, $attachmentEntries);

            $replyText = $run->output_message;

            if (!empty($replyText)) {
                $this->deliverAiReply($flowState, $node, $conversation, $agent, $run, $replyText, $decision);
            } else {
                // Nothing to send, and staying on the node means the customer
                // waits for a bot that has already given up. A human takes it
                // instead — the same ending as any other AI failure, because
                // from where the customer is sitting it is the same failure.
                Log::warning('FlowExecutor: AIAgent run returned empty reply, handing off to human', [
                    'node_id' => $node->id,
                    'run_id' => $run->id,
                    'status' => $run->status,
                    'error' => $run->error,
                ]);

                $stateData[$reasonKey] = 'error';
                $flowState->update(['state_data' => $stateData]);
                $this->routeHandoff($flowState, $node, 'error', false);

                return;
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
        } catch (AiRunQuotaExceededException $th) {
            // Same consequence as any other AI failure — a human takes over —
            // but recorded under its own reason: "the plan ran out" is an
            // account problem somebody can fix, not an outage to investigate.
            Log::warning('FlowExecutor: AIAgent run quota exhausted, handing off to human', [
                'node_id' => $node->id,
                'ai_hub_agent_id' => $agent->id,
                'limit' => $th->limit,
                'used' => $th->used,
            ]);

            $stateData[$reasonKey] = 'ai_quota_exceeded';
            $flowState->update(['state_data' => $stateData]);
            $this->routeHandoff($flowState, $node, 'ai_quota_exceeded', false);
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
     * Put the agent's answer in front of the customer, spoken or written.
     *
     * One rule governs the whole method: the customer is never left with
     * nothing. The voice can fail in three places this app does not control —
     * the hub may report `failed`, the file may not download before its
     * `expiresAt`, the channel may refuse the upload — and each of those falls
     * back to the words, which existed all along.
     *
     * @param  string  $decision  what AiDeliveryPolicy::decide() asked for
     */
    protected function deliverAiReply(
        FlowState $flowState,
        FlowNode $node,
        Conversation $conversation,
        AiHubAgent $agent,
        AiHubRun $run,
        string $replyText,
        string $decision
    ): void {
        // Now the answer exists, so the half of the policy that needed to read
        // it can run: a host and a port, a password, a walkthrough — things
        // nobody can act on by ear get the written version alongside the voice.
        $final = AiDeliveryPolicy::reconsider($decision, $replyText, (bool) $run->handoff_triggered);

        if ($final !== $decision) {
            Log::info('FlowExecutor: AIAgent answer is not one to listen to, sending the text as well', [
                'node_id' => $node->id,
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'from' => $decision,
                'to' => $final,
            ]);
        }

        $speak = AiDeliveryPolicy::speaks($final);

        // Written first when both are going out: reading is instant, and a
        // customer who has already read the answer can listen at their leisure.
        $sent = AiDeliveryPolicy::writes($final)
            ? $this->sendAiText($flowState, $conversation, $agent, $run, $replyText)
            : null;

        if ($speak) {
            $spoken = $this->sendAiVoice($flowState, $conversation, $agent, $run, $replyText);

            if ($spoken === null && $sent === null) {
                Log::warning('FlowExecutor: AIAgent voice reply failed, sending the text instead', [
                    'node_id' => $node->id,
                    'conversation_id' => $conversation->id,
                    'run_id' => $run->id,
                ]);

                $sent = $this->sendAiText($flowState, $conversation, $agent, $run, $replyText);
            }

            $sent ??= $spoken;
        }

        // Remembered for the next turn: two voice notes in a row at somebody
        // who is typing reads as not listening.
        $this->rememberDelivery($flowState, $node, $speak && $sent !== null);

        if ($sent) {
            $run->update(['message_id' => $sent->id]);
        }
    }

    /** Whether the agent's last turn was spoken — read by the dynamic policy. */
    protected function rememberDelivery(FlowState $flowState, FlowNode $node, bool $spoken): void
    {
        $key = "_ai_last_spoken_{$node->id}";

        $flowState->refresh();
        $stateData = $flowState->state_data ?? [];

        if ((bool) ($stateData[$key] ?? false) === $spoken) {
            return;
        }

        $stateData[$key] = $spoken;
        $flowState->update(['state_data' => $stateData]);
    }

    /** The agent's reply as a text message. */
    protected function sendAiText(
        FlowState $flowState,
        Conversation $conversation,
        AiHubAgent $agent,
        AiHubRun $run,
        string $replyText
    ): ?Message {
        $message = $this->messageService->sendMessage($conversation, ['message' => $replyText]);

        return $message ? $this->stampAiMessage($message, $flowState, $agent, $run) : null;
    }

    /**
     * The agent's reply as a voice note.
     *
     * The words ride along in `meta.transcription` — the same field an
     * inbound voice note carries, rendered by the same bubble. Nothing is sent
     * twice: it is there so the thread stays readable for the human who takes
     * the conversation over, who would otherwise have to press play on every
     * bubble to find out what the bot promised.
     */
    protected function sendAiVoice(
        FlowState $flowState,
        Conversation $conversation,
        AiHubAgent $agent,
        AiHubRun $run,
        string $replyText
    ): ?Message {
        $audio = (array) data_get($run->metadata, 'responseAudio', []);
        $status = $audio['status'] ?? null;
        $url = $audio['url'] ?? null;

        if ($status !== 'generated' || ! is_string($url) || $url === '') {
            Log::warning('FlowExecutor: AIAgent asked for a voice reply and did not get one', [
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'status' => $status,
            ]);

            return null;
        }

        try {
            // The hub echoes the format it produced; when it does not, what we
            // asked this channel for is a better guess than a fixed default.
            $format = (string) ($audio['format'] ?? '') ?: AiVoiceReply::format($conversation->connection->channel);

            $file = AiVoiceReply::download($url, $format, $audio['mimeType'] ?? null);

            if ($file === null) {
                return null;
            }

            $message = $this->messageService->sendAudio($conversation, ['audio' => $file]);
        } catch (\Throwable $th) {
            Log::warning('FlowExecutor: sending the AI voice reply failed', [
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'error' => $th->getMessage(),
            ]);

            return null;
        }

        return $message
            ? $this->stampAiMessage($message, $flowState, $agent, $run, [
                'transcription' => ['text' => $replyText],
            ])
            : null;
    }

    /**
     * Mark a message as this agent's work and put it on the wire.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    protected function stampAiMessage(
        Message $message,
        FlowState $flowState,
        AiHubAgent $agent,
        AiHubRun $run,
        array $extraMeta = []
    ): Message {
        $message->update([
            'sent_by_flow_id' => $flowState->flow_id,
            'sent_by_ai_hub_agent_id' => $agent->id,
            'meta' => array_merge((array) ($message->meta ?? []), [
                'ai_generated' => true,
                'ai_hub_run_id' => $run->id,
                'ai_hub_agent_id' => $agent->id,
            ], $extraMeta),
        ]);

        broadcast(new MessageReceived($message));

        return $message;
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
