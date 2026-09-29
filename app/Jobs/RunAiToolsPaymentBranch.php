<?php

namespace App\Jobs;

use App\Models\FlowPayment;
use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A cart the AI charged was settled: take the node's `paid` or
 * `payment_failed` output.
 *
 * A job, not an inline call from the webhook, because it must wait its turn:
 * the Pix can be paid while the model is still writing its reply, and leaving
 * the AI node under a turn in flight would put the next step's message and
 * the bot's answer in the chat in the wrong order. It takes the same
 * per-conversation lock the AI turn does, and stands back while it is held.
 */
class RunAiToolsPaymentBranch implements ShouldQueue
{
    use Queueable;

    /** Spent waiting out a turn in flight, like RunAiAgentTurn's. */
    public int $tries = 20;

    public int $timeout = 120;

    private const RETRY_SECONDS = 10;

    public function __construct(public int $flowPaymentId) {}

    public function handle(): void
    {
        $payment = FlowPayment::find($this->flowPaymentId);

        if (! $payment) {
            return;
        }

        if (! (new FlowExecutor)->takeAiToolsPaymentBranch($payment)) {
            $this->release(self::RETRY_SECONDS);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunAiToolsPaymentBranch: the payment outcome never reached the flow', [
            'flow_payment_id' => $this->flowPaymentId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
