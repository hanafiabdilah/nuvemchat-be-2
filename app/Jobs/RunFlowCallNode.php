<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The latest moment a Call node stops waiting on its call.
 *
 * Normally the core's result event gets there first and moves the flow on
 * with the outcome; this is the backstop for a call whose end never reaches
 * us, so a funnel is never left parked on a phone that stopped ringing long
 * ago. {{call_status}} then reads "unknown".
 *
 * A single attempt for the same reason as RunFlowIntervalNode: the token in
 * the flow state is what makes this safe at all, and a retry after the flow
 * has moved on would drag it back.
 */
class RunFlowCallNode implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** It hands straight on to the next node, which may be a send. */
    public int $timeout = 180;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public string $token,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runCallFinished($this->flowStateId, $this->nodeId, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowCallNode: the flow never came back from its call', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
