<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One flow turn, off the webhook request.
 *
 * Dispatched only when `flow.queue` is on — see config/flow.php and
 * App\Services\Flow\FlowRunner, which is the only thing that should construct
 * this.
 */
class RunFlowTurn implements ShouldQueue
{
    use Queueable;

    public const START = 'start';

    public const RESUME = 'resume';

    /**
     * ⚠️ One attempt, never more.
     *
     * A retried flow turn is not a second attempt at one delivery — it is a
     * second set of bubbles in front of a customer who already received the
     * first set, and a second run of whatever the flow's http_request node
     * does on somebody else's system. The same reasoning as $tries=1 on
     * SendAiHoldingMessage.
     *
     * A turn that throws is logged and dropped; the customer's next message
     * resumes the flow from wherever it actually got to, because the executor
     * keeps its position in flow_states rather than in this job.
     */
    public int $tries = 1;

    /**
     * Above the http_request ceiling (config('flow.http_max_timeout'), 120s)
     * plus the channel calls around it, so the worker's own limit is never the
     * thing that kills a turn that was going to finish.
     */
    public int $timeout = 240;

    public function __construct(
        private readonly int $conversationId,
        private readonly string $mode,
        private readonly string $userInput = '',
    ) {}

    public function handle(FlowExecutor $executor): void
    {
        // ⚠️ Re-read, never serialise the model. Between the webhook and this
        // job an agent may have taken the thread over, it may have been
        // resolved, or the flow may have been detached from the connection —
        // and a serialised copy would carry the state from before all of that.
        // The executor's own guards then decide, exactly as they do inline.
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        try {
            if ($this->mode === self::START) {
                $executor->startFlow($conversation);
            } else {
                $executor->resumeFlow($conversation, $this->userInput);
            }
        } catch (\Throwable $th) {
            // Logged rather than rethrown: with $tries=1 a rethrow only moves
            // the same message into failed_jobs, and the call sites this
            // replaced all swallowed their errors the same way — a broken flow
            // has never been allowed to fail the delivery around it.
            Log::error('RunFlowTurn: flow turn failed', [
                'conversation_id' => $this->conversationId,
                'mode' => $this->mode,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
