<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Nobody sent a receipt in time: take the Receipt node's `timeout` branch. */
class RunFlowReceiptTimeout implements ShouldQueue
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
        (new FlowExecutor)->runReceiptTimeout($this->flowStateId, $this->nodeId, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunFlowReceiptTimeout: timeout branch never ran', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
