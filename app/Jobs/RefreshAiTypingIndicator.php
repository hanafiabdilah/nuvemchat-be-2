<?php

namespace App\Jobs;

use App\Services\AiAgentHub\AiTypingPresence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One beat of the "digitando…" shown while an AI turn is in flight.
 *
 * Every typing indicator on every channel is a dead man's switch — asserted
 * once, it expires in seconds — so this job re-queues itself until the episode
 * ends. The two things that stop it are both in AiTypingPresence: the cache
 * token disappearing (the turn finished, or somebody took the conversation) and
 * the deadline this carries.
 *
 * $tries is 1: a beat that fails is a beat missed, and the next one is already
 * on its way. Retrying would only stack indicators behind a channel that is
 * having a bad minute.
 */
class RefreshAiTypingIndicator implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $conversationId,
        public string $token,
        /** Unix timestamp past which the episode ends, whatever else is true. */
        public int $deadline,
        /** Position in the episode, counted so a frozen clock still ends it. */
        public int $beat = 1,
    ) {}

    public function handle(): void
    {
        AiTypingPresence::refresh(
            $this->conversationId,
            $this->token,
            $this->deadline,
            $this->beat,
        );
    }
}
