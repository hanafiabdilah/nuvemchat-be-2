<?php

namespace App\Services\AiAgentHub;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Events\MessageReceived;
use App\Exceptions\PublicApiException;
use App\Models\AiHubAgent;
use App\Models\AiProactiveMessage;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Message\MessageService;
use App\Services\Messaging\MessagingWindow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The AI Hub writing into a conversation without the customer having written
 * first.
 *
 * Everything a chat platform does is normally triggered by an inbound message.
 * This is the case that is not: the customer followed a link, logged into the
 * partner's portal, and the agent now has something to say — while the customer
 * sits looking at a chat that has gone quiet. Nothing in the existing surfaces
 * covers it. `POST /api/v1/send-message` sends to a *number*, outside any
 * thread, so the bubble would never appear in the conversation, never be
 * attributed to the agent, and never reach the dashboard in realtime.
 *
 * Three guarantees shape the code:
 *
 *  1. **A valid reference is permission to ask, never permission to send.**
 *     AiCallbackRef proves the run happened; whether the thread is still with
 *     the AI is live state, re-read on every call. An agent who took the
 *     conversation over between the run and the callback keeps it.
 *  2. **Every refusal happens before anything is written.** A 409 leaves no row
 *     behind and does not consume the idempotency key, so the hub is free to
 *     reuse it once the situation changes.
 *  3. **A retry never sends twice.** The record is written before the send, so
 *     it exists in the gap a timeout hides in.
 *
 * What the caller does *not* get to decide: who the message is from. The
 * request carries no sender field — attribution comes from the reference, which
 * names the agent. A caller able to declare itself is a caller that will one
 * day declare itself a human.
 */
final class AiProactiveMessageService
{
    /** A row whose request died mid-way must not answer "processing" forever. */
    private const STALE_MINUTES = 5;

    public function __construct(
        private MessageService $messages,
    ) {}

    /**
     * @param  array{callback_ref: string, text: string}  $data  already validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function send(Tenant $tenant, ApiKey $key, string $idempotencyKey, array $data): array
    {
        if (! AiCallbackRef::enabled()) {
            throw new PublicApiException(
                'As mensagens proativas estão desativadas nesta plataforma.',
                'proactive_messages_disabled',
                403,
            );
        }

        if ($replay = $this->replay($tenant, $idempotencyKey)) {
            return $replay;
        }

        $claims = AiCallbackRef::open($data['callback_ref']);

        $conversation = Conversation::with(['connection', 'contact'])->find($claims['conversation_id']);

        // A reference minted for a workspace cannot be spent in another, and a
        // conversation that no longer exists is indistinguishable from one that
        // never did — both answer the same way, so neither confirms anything to
        // a caller probing with somebody else's reference.
        if (! $conversation || $conversation->connection?->tenant_id !== $tenant->id) {
            throw new PublicApiException(
                'Conversa não encontrada.',
                'conversation_not_found',
                404,
            );
        }

        $agent = $this->assertStillWithTheAgent($conversation, $claims);

        $this->assertWindowOpen($conversation);
        $this->assertWithinConversationCeiling($conversation);

        $text = $data['text'];

        try {
            $record = AiProactiveMessage::create([
                'tenant_id' => $tenant->id,
                'api_key_id' => $key->id,
                'idempotency_key' => $idempotencyKey,
                'conversation_id' => $conversation->id,
                'ai_hub_agent_id' => $agent->id,
                'payload' => ['text' => $text],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two retries raced past the lookup above; the loser answers with
            // whatever the winner produced.
            return $this->replay($tenant, $idempotencyKey)
                ?? throw new PublicApiException(
                    'Esta mensagem ainda está sendo enviada. Tente de novo em alguns segundos.',
                    'message_in_progress',
                    409,
                );
        }

        try {
            $message = $this->messages->sendMessage($conversation, ['message' => $text]);
        } catch (\Throwable $th) {
            // The record stays. This is the case it was written for: a send that
            // threw may still have reached the channel — a timeout on our side
            // says nothing about whether WhatsApp took the text — and deleting
            // the row here would let the caller's retry put a second message in
            // somebody's chat. For the stale window the retry is told to hold;
            // after it, the row is cleared and a fresh attempt may proceed.
            $this->markFailed($record, $th->getMessage());

            throw $th;
        }

        if (! $message) {
            $this->markFailed($record, 'the channel handler returned nothing');

            throw new PublicApiException(
                'O canal não aceitou a mensagem. Tente novamente em instantes.',
                'message_not_sent',
                502,
            );
        }

        $this->stamp($message, $agent, $conversation);

        RateLimiter::hit($this->ceilingKey($conversation), 3600);

        $body = [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'duplicate' => false,
        ];

        $record->update([
            'message_id' => $message->id,
            'status' => AiProactiveMessage::STATUS_SENT,
            'result' => $body,
        ]);

        Log::info('AiProactiveMessage: the hub wrote into a conversation', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'ai_hub_agent_id' => $agent->id,
            'api_key_id' => $key->id,
            'tenant_id' => $tenant->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        return ['status' => 201, 'body' => $body];
    }

    /**
     * Is this thread still the agent's to speak in?
     *
     * Four ways it can stop being, and they are deliberately four different
     * answers: the hub decides what to do next from the code, and "somebody is
     * handling this" is not the same instruction as "this thread is over".
     */
    private function assertStillWithTheAgent(Conversation $conversation, array $claims): AiHubAgent
    {
        if ($conversation->status === ConversationStatus::Active) {
            throw new PublicApiException(
                'Um atendente assumiu esta conversa.',
                'conversation_with_human',
                409,
            );
        }

        if (! in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            throw new PublicApiException(
                'Esta conversa foi encerrada.',
                'conversation_closed',
                409,
            );
        }

        $flowState = FlowState::with('currentNode')->where('conversation_id', $conversation->id)->first();
        $node = $flowState?->currentNode;

        // The flow having moved on is how a handoff revokes the reference
        // without anything being revoked: transferToHuman stops the flow, and a
        // flow that reached another node is no longer this agent's turn.
        if (! $flowState
            || $flowState->status !== FlowStateStatus::Running
            || ! $node
            || $node->id !== $claims['flow_node_id']
            || $node->type !== NodeType::AIAgent) {
            throw new PublicApiException(
                'Esta conversa não está mais sendo atendida por este agente de IA.',
                'conversation_not_with_ai',
                409,
            );
        }

        $agentId = ($node->data ?? [])['ai_hub_agent_id'] ?? null;

        // The node still stands but points at a different agent now: somebody
        // edited the flow. The reference names the agent it was minted for, and
        // it does not carry over to its replacement.
        if ((int) $agentId !== $claims['ai_hub_agent_id']) {
            throw new PublicApiException(
                'Esta conversa não está mais sendo atendida por este agente de IA.',
                'conversation_not_with_ai',
                409,
            );
        }

        $agent = AiHubAgent::find($claims['ai_hub_agent_id']);

        if (! $agent) {
            throw new PublicApiException(
                'Esta conversa não está mais sendo atendida por este agente de IA.',
                'conversation_not_with_ai',
                409,
            );
        }

        return $agent;
    }

    /**
     * WhatsApp Official refuses free-form content once the session window has
     * closed — and often refuses it by answering 200 and rejecting it later, so
     * asking first is the only way to tell the truth here.
     *
     * Unlike the dashboard's guard, this does NOT resolve the conversation on
     * the way out: closing somebody's thread as a side effect of a partner's
     * callback is not a decision this surface gets to make.
     */
    private function assertWindowOpen(Conversation $conversation): void
    {
        if (MessagingWindow::isOpen($conversation)) {
            return;
        }

        $channel = $conversation->connection->channel;

        throw new PublicApiException(
            'A janela de mensagens deste canal está fechada; só é possível escrever depois que o cliente enviar uma nova mensagem.',
            'messaging_window_closed',
            422,
            [
                'window' => [
                    'channel' => $channel->value,
                    'hours' => MessagingWindow::hoursFor($channel),
                    'closed_at' => MessagingWindow::closesAt($conversation)?->toIso8601String(),
                ],
            ],
        );
    }

    private function assertWithinConversationCeiling(Conversation $conversation): void
    {
        $max = max(1, (int) config('ai.proactive.max_per_conversation_per_hour', 6));

        if (! RateLimiter::tooManyAttempts($this->ceilingKey($conversation), $max)) {
            return;
        }

        Log::warning('AiProactiveMessage: conversation ceiling reached', [
            'conversation_id' => $conversation->id,
            'max_per_hour' => $max,
        ]);

        throw new PublicApiException(
            'Muitas mensagens proativas nesta conversa na última hora.',
            'too_many_proactive_messages',
            429,
            ['retry_after' => RateLimiter::availableIn($this->ceilingKey($conversation))],
        );
    }

    private function ceilingKey(Conversation $conversation): string
    {
        return "ai-proactive:{$conversation->id}";
    }

    /**
     * Mark the message as this agent's work.
     *
     * `ai_hub_proactive` is not decoration. The next turn's transcript
     * (AiConversationContext) skips what the hub already knows by looking for
     * `ai_hub_run_id`, and this message has none — there was no run on our side.
     * Without the flag the hub would be handed back its own sentence, and worse:
     * text in `message.content` is scanned by the hub's handoff detector, so a
     * proactive message that happened to mention an "atendente" would hand the
     * conversation to a human on the following turn. That exact failure cost 53
     * out of 53 runs in September 2026.
     */
    private function stamp(Message $message, AiHubAgent $agent, Conversation $conversation): void
    {
        $flowId = FlowState::where('conversation_id', $conversation->id)->value('flow_id');

        $message->update([
            'sent_by_flow_id' => $flowId,
            'sent_by_ai_hub_agent_id' => $agent->id,
            'meta' => array_merge((array) ($message->meta ?? []), [
                'ai_generated' => true,
                'ai_hub_agent_id' => $agent->id,
                'ai_hub_proactive' => true,
            ]),
        ]);

        broadcast(new MessageReceived($message));
    }

    /** Keep the row, remember why, and say so in the log. */
    private function markFailed(AiProactiveMessage $record, string $reason): void
    {
        $record->update([
            'status' => AiProactiveMessage::STATUS_FAILED,
            'payload' => array_merge((array) $record->payload, ['error' => $reason]),
        ]);

        Log::warning('AiProactiveMessage: the channel did not take the message', [
            'conversation_id' => $record->conversation_id,
            'idempotency_key' => $record->idempotency_key,
            'error' => $reason,
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function replay(Tenant $tenant, string $idempotencyKey): ?array
    {
        $record = AiProactiveMessage::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $record) {
            return null;
        }

        if ($record->result === null) {
            // Either still in flight, or a send whose fate we never learned.
            // Both answer the same way and for the same reason: we cannot say
            // the customer did not get it, so the caller must not send again
            // yet. Past the window the request is taken as dead and a retry
            // starts over — the alternative is a conversation that can never be
            // written to again because of one timeout.
            if ($record->created_at?->lt(now()->subMinutes(self::STALE_MINUTES))) {
                $record->delete();

                return null;
            }

            throw new PublicApiException(
                'Esta mensagem ainda está sendo enviada, ou o envio não foi confirmado. Tente de novo em alguns minutos.',
                'message_in_progress',
                409,
            );
        }

        return ['status' => 200, 'body' => array_merge($record->result, ['duplicate' => true])];
    }
}
