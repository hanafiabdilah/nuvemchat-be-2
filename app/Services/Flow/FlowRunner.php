<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunFlowTurn;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Services\Conversation\LastAgentRouter;

/**
 * The one place that decides *where* a flow turn runs.
 *
 * Eight call sites start or resume flows — seven chat handlers and the widget —
 * and every one of them used to construct a FlowExecutor and call it inline,
 * inside the request that delivered the customer's message. That is what put
 * the channel API calls of every message node, and whatever endpoint an
 * http_request node names, on the critical path of a webhook: enough inbound
 * traffic against a slow flow and the PHP-FPM pool is full, which is the whole
 * platform answering 502 rather than one workspace being slow.
 *
 * Routing that through here means the decision is made once, and flipping it is
 * a config change rather than eight edits.
 *
 * ⚠️ Inline is still the default (config/flow.php explains why). This class
 * changes nothing until somebody turns the switch on, and with it off the
 * behaviour is identical to what the call sites did before — the same executor,
 * in the same request, in the same order.
 */
final class FlowRunner
{
    public static function start(Conversation $conversation): void
    {
        self::run($conversation, RunFlowTurn::START);
    }

    public static function resume(Conversation $conversation, string $userInput): void
    {
        // A thread something other than the customer opened — today, a call
        // log (CallLog creates the conversation to hold the note) — reaches
        // the handlers as an *existing* conversation, so the customer's first
        // real message is treated as a follow-up and resumed. With no flow
        // state there is nothing to resume, and the bot never greets them.
        // That message is still the opening one, so it gets the opening move.
        if (self::openIfFirst($conversation)) {
            return;
        }

        self::run($conversation, RunFlowTurn::RESUME, $userInput);
    }

    /**
     * Give the thread its opening move if the message just stored is the
     * customer's first word in it; false, and nothing done, if it is not.
     */
    public static function openIfFirst(Conversation $conversation): bool
    {
        if (! self::opensThread($conversation)) {
            return false;
        }

        if (! LastAgentRouter::route($conversation) && $conversation->connection?->flow_id) {
            self::start($conversation);
        }

        return true;
    }

    /**
     * Whether the message just stored is the customer's first word in a
     * thread nobody has acted on: still in the queue, unassigned, no flow ever
     * run, nothing said to them, and exactly one message from them. Info notes
     * (call logs, transfers) are not conversation and do not count.
     *
     * "Nothing said to them" is judged by when things were said, not by when
     * they reached us. On WhatsApp the business phone can answer a message we
     * have not been handed yet — its first delivery failed to decrypt and the
     * retry is still on its way — so the phone's echo opens the thread and the
     * customer's message lands second. They still spoke first, and it is still
     * the opening message. A thread the business started stays as it was: the
     * bot does not walk into a conversation somebody else began.
     */
    private static function opensThread(Conversation $conversation): bool
    {
        if ($conversation->status !== Status::Pending
            || $conversation->user_id !== null
            || $conversation->isGroup()
            || FlowState::where('conversation_id', $conversation->id)->exists()) {
            return false;
        }

        $said = $conversation->messages()
            ->where('message_type', '!=', MessageType::Info)
            ->selectRaw('sender_type, COUNT(*) as total')
            ->groupBy('sender_type')
            ->pluck('total', 'sender_type');

        if ((int) ($said[SenderType::Incoming->value] ?? 0) !== 1) {
            return false;
        }

        if ((int) ($said[SenderType::Outgoing->value] ?? 0) === 0) {
            return true;
        }

        $askedAt = $conversation->messages()
            ->where('message_type', '!=', MessageType::Info)
            ->where('sender_type', SenderType::Incoming)
            ->toBase()
            ->value('sent_at');

        if ($askedAt === null) {
            return false;
        }

        // Every reply has to be the connected phone's own (nothing sent from
        // the panel, a flow or the AI) and strictly later than the customer.
        return ! $conversation->messages()
            ->where('message_type', '!=', MessageType::Info)
            ->where('sender_type', SenderType::Outgoing)
            ->where(fn ($q) => $q
                ->whereNotNull('sent_by_user_id')
                ->orWhereNotNull('sent_by_flow_id')
                ->orWhereNotNull('sent_by_ai_hub_agent_id')
                ->orWhereNull('sent_at')
                ->orWhere('sent_at', '<=', $askedAt))
            ->exists();
    }

    private static function run(Conversation $conversation, string $mode, string $userInput = ''): void
    {
        if (! config('flow.queue')) {
            $executor = app(FlowExecutor::class);

            if ($mode === RunFlowTurn::START) {
                $executor->startFlow($conversation);
            } else {
                $executor->resumeFlow($conversation, $userInput);
            }

            return;
        }

        RunFlowTurn::dispatch($conversation->id, $mode, $userInput)
            ->onQueue((string) config('flow.queue_name', 'default'));
    }
}
