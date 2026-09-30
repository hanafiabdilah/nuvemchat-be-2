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
        if (self::opensThread($conversation)) {
            if (! LastAgentRouter::route($conversation) && $conversation->connection?->flow_id) {
                self::start($conversation);
            }

            return;
        }

        self::run($conversation, RunFlowTurn::RESUME, $userInput);
    }

    /**
     * Whether the message just stored is the customer's first word in a
     * thread nobody has acted on: still in the queue, unassigned, no flow ever
     * run, nothing said to them, and exactly one message from them. Info notes
     * (call logs, transfers) are not conversation and do not count.
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

        return (int) ($said[SenderType::Incoming->value] ?? 0) === 1
            && (int) ($said[SenderType::Outgoing->value] ?? 0) === 0;
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
