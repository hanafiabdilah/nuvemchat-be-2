<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The moment a Wait for reply node stops waiting.
 *
 * Armed when the node parks, disarmed the moment the customer writes. If this
 * job still finds its own token in the flow state, nobody answered — and the
 * flow takes the node's `timeout` branch instead of sitting on a wait that was
 * never going to end.
 *
 * A single attempt on purpose: the token is what makes this safe to run at all,
 * and a retry after the branch has already been taken would move a flow that
 * has moved on.
 */
class RunFlowWaitResponseTimeout implements ShouldQueue
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
        (new FlowExecutor)->runWaitResponseTimeout(
            $this->flowStateId,
            $this->nodeId,
            $this->token,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowWaitResponseTimeout: timeout branch never ran', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
