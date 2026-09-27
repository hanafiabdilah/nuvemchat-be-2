<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One bubble of a Message node that sends several, with pauses between them.
 *
 * The pause used to be a `sleep()` inside the webhook request that delivered
 * the customer's message — which Meta and Telegram both retry when it takes too
 * long, so a node with a 30-second delay was buying duplicate inbound messages.
 * A node with three of them would be worse.
 *
 * Each job sends its own bubble and dispatches the next one delayed by that
 * bubble's pause, so the chain walks itself forward. The last one moves the
 * flow on. A chain token in the flow state says which chain owns the node:
 * anything holding a stale token steps aside rather than sending twice.
 */
class RunFlowMessageNode implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, like RunBroadcastJob: a retry here re-sends a message the
     * customer may already have. FlowExecutor swallows a failed send and walks
     * the chain on regardless, so a single dead bubble costs that bubble rather
     * than the rest of the sequence.
     */
    public int $tries = 1;

    /**
     * Bound on one bubble, so the worker default never has to be.
     *
     * ⚠️ A job that has no timeout of its own inherits the worker's, and a job
     * that outlives it does not merely fail — the `queue:work` process exits, so
     * everything queued behind it waits for the container to come back. A media
     * bubble can legitimately take a while (a URL send that times out, then a
     * download and re-upload), which is close enough to the worker's 60s default
     * to be worth stating here rather than inheriting.
     *
     * Two minutes is also the point past which a bubble is not worth delivering:
     * the customer has moved on.
     */
    public int $timeout = 120;

    /**
     * How many sends of this bubble have already failed.
     *
     * ⚠️ Declared with a default instead of promoted into the constructor, and
     * that is load-bearing: a job serialised by the previous release carries no
     * such property, and a promoted one would come back from the queue
     * uninitialised — every bubble already waiting out a pause across the deploy
     * would die on first access. A property default is applied by unserialize.
     */
    public int $attempt = 0;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public int $index,
        public string $token,
        int $attempt = 0,
    ) {
        $this->attempt = $attempt;
    }

    public function handle(): void
    {
        (new FlowExecutor)->runScheduledMessageItem(
            $this->flowStateId,
            $this->nodeId,
            $this->index,
            $this->token,
            $this->attempt,
        );
    }

    /**
     * The chain broke. Worth a line: the customer got part of a sequence and
     * the flow never moved past the node that was sending it.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowMessageNode: message sequence stopped', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'index' => $this->index,
            'attempt' => $this->attempt,
            'error' => $exception?->getMessage(),
        ]);
    }
}
