<?php

namespace App\Services\Flow;

use App\Jobs\RunFlowTurn;
use App\Models\Conversation;

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
        self::run($conversation, RunFlowTurn::RESUME, $userInput);
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
