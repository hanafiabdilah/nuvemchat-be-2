<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The "um momento…" line, if the answer is still not ready by the time this
 * wakes up.
 *
 * Scheduled rather than sent inline, and that is the whole point: a reply that
 * lands in two seconds must not be preceded by an apology for a delay that
 * never happened. Everything that decides whether it is still owed lives in
 * FlowExecutor::sendAiHoldingMessage — the same shape as RunAiAgentTurn, whose
 * checks also belong to the executor rather than to the queue.
 *
 * $tries is 1 on purpose. A retry here is not a second attempt at one message;
 * it is a second bubble saying the same thing to a customer who by then has
 * probably been answered. Losing the courtesy is the cheaper failure.
 */
class SendAiHoldingMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public string $token,
        /** The newest customer message this turn owes an answer to. */
        public int $afterMessageId,
        /** Whether the customer sent something rather than typed it. */
        public bool $media,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->sendAiHoldingMessage(
            $this->flowStateId,
            $this->nodeId,
            $this->token,
            $this->afterMessageId,
            $this->media,
        );
    }
}
