<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The end of a Wait for reply node's burst window.
 *
 * Every message the customer sends while the window is open writes a new token
 * and queues another of these, so the window slides rather than stacks: only
 * the job holding the newest token finds it still in the flow state, and that
 * one reads everything that arrived as a single reply. The rest step aside.
 *
 * A single attempt for the reason the timeout job has one: a retry after the
 * reply was handled would handle it again.
 */
class RunFlowWaitResponseBuffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public string $token,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runWaitResponseBuffer(
            $this->flowStateId,
            $this->nodeId,
            $this->token,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowWaitResponseBuffer: the buffered reply was never handled', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
