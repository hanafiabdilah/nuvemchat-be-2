<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of an AI follow-up, if the customer is still silent when it wakes.
 *
 * Every check that decides whether it is still owed lives in
 * FlowExecutor::runAiFollowUp — the same shape as RunAiAgentTurn.
 *
 * $tries is 1 on purpose: a retry is not a second attempt at one message but a
 * second nudge to somebody who may have been nudged already.
 */
class SendAiFollowUp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Above the hub's own request timeout, with the send that follows it. */
    public int $timeout = 240;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public string $token,
    ) {}

    public function handle(): void
    {
        (new FlowExecutor)->runAiFollowUp($this->flowStateId, $this->nodeId, $this->token);
    }
}
