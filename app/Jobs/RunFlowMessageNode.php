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

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public int $index,
        public string $token,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runScheduledMessageItem(
            $this->flowStateId,
            $this->nodeId,
            $this->index,
            $this->token,
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
            'error' => $exception?->getMessage(),
        ]);
    }
}
