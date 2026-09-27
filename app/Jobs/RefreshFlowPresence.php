<?php

namespace App\Jobs;

use App\Services\Flow\FlowPresence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One beat of the "digitando…" / "gravando áudio…" a flow shows while it pauses.
 *
 * Every indicator on every channel is a dead man's switch — asserted once, it
 * expires in seconds — so this job re-queues itself until the pause is over.
 * What stops it is in FlowPresence: the claim token in the flow state going
 * stale (a newer sequence, a person taking the thread) and the deadline this
 * carries, which is the instant the message is due.
 *
 * $tries is 1: a beat that fails is a beat missed, and the next one is already
 * on its way. Retrying would only stack indicators behind a channel that is
 * having a bad minute.
 */
class RefreshFlowPresence implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $flowStateId,
        /** Flow-state key whose token says this pause is still the live one. */
        public string $claimKey,
        public string $token,
        /** A PresenceKind value — carried as a string so the job survives a deploy. */
        public string $kind,
        /** Unix timestamp the pause ends at: the moment the message is due. */
        public int $deadline,
        /** Position in the episode, counted so a frozen clock still ends it. */
        public int $beat = 1,
    ) {}

    public function handle(): void
    {
        FlowPresence::beat(
            $this->flowStateId,
            $this->claimKey,
            $this->token,
            $this->kind,
            $this->deadline,
            $this->beat,
        );
    }
}
