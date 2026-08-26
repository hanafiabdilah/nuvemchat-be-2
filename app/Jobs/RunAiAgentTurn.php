<?php

namespace App\Jobs;

use App\Services\Flow\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One AI agent turn, run after the customer has stopped typing.
 *
 * Inbound messages used to drive the AI straight from the webhook: each one
 * called the hub and each one produced a reply. A customer who splits a single
 * question across four messages got four answers to three unfinished versions
 * of it — and the whole round-trip sat inside a webhook request that Meta and
 * Telegram both retry when it takes too long.
 *
 * So the turn is armed instead of run. Every message re-arms it with a fresh
 * token; when this job wakes up it only proceeds if the token in the flow state
 * is still its own, which is precisely the message that arrived last. Anything
 * queued behind a newer message finds a stranger's token and steps aside.
 */
class RunAiAgentTurn implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts are spent waiting out a turn that is already in flight, not
     * retrying a failure — see the release() below. The hub answers in seconds,
     * so this is a wide margin, and the conversation lock expires on its own
     * well before the last one is used.
     */
    public int $tries = 20;

    /** Above the hub's own request timeout, with the send that follows it. */
    public int $timeout = 240;

    /** How long to stand back when another turn holds the conversation. */
    private const RETRY_SECONDS = 10;

    public function __construct(
        public int $flowStateId,
        public int $nodeId,
        public string $token,
    ) {}

    public function handle(): void
    {
        $ran = (new FlowExecutor)->runScheduledAiTurn(
            $this->flowStateId,
            $this->nodeId,
            $this->token,
        );

        if (! $ran) {
            // Another turn is mid-flight for this conversation. Come back for
            // the messages it will not have consumed rather than dropping them.
            $this->release(self::RETRY_SECONDS);
        }
    }

    /**
     * Out of attempts, or the turn threw past FlowExecutor's own handling. The
     * customer is left without a reply, which is worth a line in the log.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('RunAiAgentTurn: AI turn never ran', [
            'flow_state_id' => $this->flowStateId,
            'node_id' => $this->nodeId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
