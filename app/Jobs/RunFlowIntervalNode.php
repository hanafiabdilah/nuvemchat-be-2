<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The moment an Interval node stops waiting.
 *
 * Armed when the node parks, and the only thing that moves the flow on from
 * there — a customer writing during the wait does not, which is the whole
 * difference between this node and Wait for reply.
 *
 * A single attempt on purpose: the token in the flow state is what makes this
 * safe to run at all, and a retry after the flow has already walked past the
 * node would drag a conversation that has moved on back through it.
 */
class RunFlowIntervalNode implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Bound on the work that follows the wait, so the worker default never has
     * to be. This job does not merely tick a clock: it hands straight on to the
     * next node, and that node may be an HTTP request or a media send.
     *
     * ⚠️ A job that outlives its timeout does not merely fail — `queue:work`
     * exits and everything behind it waits for the container to come back.
     */
    public int $timeout = 180;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public string $token,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runIntervalElapsed(
            $this->flowStateId,
            $this->nodeId,
            $this->token,
        );
    }

    /**
     * Worth a line: the flow is parked on a pause that has already elapsed and
     * nothing else is going to move it.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowIntervalNode: the flow never came back from its interval', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
