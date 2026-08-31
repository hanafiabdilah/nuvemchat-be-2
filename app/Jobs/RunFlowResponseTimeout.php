<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The moment a Response node stops waiting.
 *
 * Armed when the node sends its question, disarmed the moment an answer
 * arrives. If this job still finds its own token in the flow state, nobody
 * answered — and the flow takes the node's `timeout` branch instead of sitting
 * on a question that was never going to be answered.
 *
 * A single attempt on purpose: the token is what makes this safe to run at all,
 * and a retry after the branch has already been taken would move a flow that
 * has moved on.
 */
class RunFlowResponseTimeout implements ShouldQueue
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
        (new FlowExecutor)->runResponseTimeout(
            $this->flowStateId,
            $this->nodeId,
            $this->token,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowResponseTimeout: timeout branch never ran', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
