<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the file a Receipt node is holding and moves the flow on.
 *
 * Off the webhook: it is a vision model call, and the request that delivered
 * the customer's picture should not be the one waiting for it. One attempt —
 * a second would be a second paid run and, on a slow first one, a second
 * verdict on the same file.
 */
class CheckFlowReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public int $messageId,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runReceiptCheck($this->flowStateId, $this->nodeId, $this->messageId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('CheckFlowReceipt: the receipt was never checked', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'message_id' => $this->messageId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
