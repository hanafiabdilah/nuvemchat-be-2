<?php

namespace App\Services\Flow;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\PresenceKind;
use App\Jobs\RefreshFlowPresence;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Services\Message\MessageService;
use Illuminate\Support\Facades\Log;

/**
 * "digitando…" — and before a voice note, "gravando áudio…" — for as long as a
 * flow is deliberately pausing.
 *
 * A Message node's pause has always been silent. Its own documentation says a
 * pause exists "to make a sequence read like typing", and it never did: the
 * customer saw a number go quiet and then, twenty seconds later, produce a
 * voice note. That is not somebody preparing a reply, it is a number that looks
 * broken — and the people who do not wait it out are gone before the bubble
 * lands.
 *
 * Deliberately simpler than {@see \App\Services\AiAgentHub\AiTypingPresence},
 * which solves a harder problem: an AI turn is of unknown length, so it needs a
 * cache token and a loop that runs until somebody cancels it. A flow pause is
 * of *known* length. That means no new cache key and no new notion of
 * ownership — the beats validate the claim the pausing node already holds in
 * the flow state (`_message_chain_{id}`, `_interval_{id}`), so a sequence that
 * was taken over, abandoned or superseded silences its own indicator with no
 * extra bookkeeping.
 *
 * Everything here is best-effort and silent. A decoration must never be the
 * reason a message does not go out.
 */
final class FlowPresence
{
    /**
     * Hard ceiling on the configured window.
     *
     * The same number the Message node caps a pause at: past five minutes this
     * stops being a pause in a conversation, and an indicator asserted for
     * longer than that is a bug wearing a feature's clothes.
     */
    private const MAX_WINDOW_CEILING = 300;

    /**
     * Hard stop on the beats in one episode, whatever the clock says.
     *
     * The deadline is the real limit; this covers the case the deadline cannot
     * — a queue where time does not move between a job and the job it queues.
     * A self-dispatching job with only a wall-clock stop is one frozen clock
     * away from typing at a customer forever, and on API Way, whose indicator
     * has no timeout at all, "forever" is the literal word.
     */
    private const MAX_BEATS = 100;

    /**
     * Start showing the indicator across a pause that ends in `$pauseSeconds`.
     *
     * ⚠️ The episode covers the *tail* of the pause, not its head. A 180-second
     * wait shown as three minutes of "gravando áudio…" is not a person; a wait
     * that is quiet and then, in its last stretch, starts recording, is. The
     * lead-in is why this dispatches delayed rather than asserting inline —
     * which also keeps an HTTP call to the channel out of the webhook request
     * that delivered the customer's message.
     *
     * @param  string  $claimKey  flow-state key whose token says this pause is
     *                            still the one running
     */
    public static function start(
        FlowState $flowState,
        string $claimKey,
        string $token,
        int $pauseSeconds,
        PresenceKind $kind = PresenceKind::Typing,
    ): void {
        $conversation = $flowState->conversation;

        if (! $conversation || ! self::usable($conversation)) {
            return;
        }

        $window = min($pauseSeconds, self::maxSeconds());

        if ($window < 1) {
            return;
        }

        $leadIn = max(0, $pauseSeconds - $window);

        RefreshFlowPresence::dispatch(
            $flowState->id,
            $claimKey,
            $token,
            $kind->value,
            now()->addSeconds($pauseSeconds)->timestamp,
        )->delay(now()->addSeconds($leadIn))->onQueue(self::queue());
    }

    /**
     * One beat: assert the indicator and queue the next, until the pause ends.
     *
     * Every early return is an episode that is no longer anybody's: a newer
     * sequence took the node, a person took the conversation, the pause is
     * over. Nothing to report — the indicator expires on the channel's own
     * countdown, and the message that follows clears it everywhere.
     */
    public static function beat(
        int $flowStateId,
        string $claimKey,
        string $token,
        string $kind,
        int $deadline,
        int $beat = 1,
    ): void {
        if ($beat > self::MAX_BEATS) {
            return;
        }

        $flowState = FlowState::with('conversation.connection')->find($flowStateId);

        if (! $flowState || self::claimToken($flowState, $claimKey) !== $token) {
            return;
        }

        $conversation = $flowState->conversation;

        if (! $conversation || ! self::usable($conversation)) {
            return;
        }

        // Somebody took the thread off the bot while this was queued. Withdraw
        // rather than leave them watching a bot that is no longer answering.
        if (! in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            self::stop($conversation);

            return;
        }

        // The pause is over. Deliberately no withdrawal: the bubble is landing
        // in the same instant, and every channel clears the indicator when a
        // message arrives. A `paused` sent here would only race that send.
        if (now()->timestamp >= $deadline) {
            return;
        }

        $presence = PresenceKind::tryFrom($kind) ?? PresenceKind::Typing;

        (new MessageService)->sendTyping($conversation, true, $presence);

        $interval = $conversation->connection?->channel?->typingRefreshSeconds();

        if (! $interval) {
            return;
        }

        // On a `sync` queue there is no later: a delayed dispatch runs now, so
        // a beat would re-enter itself immediately and forever. The honest
        // behaviour there is a single beat — an indicator that appears once and
        // expires on the channel's own countdown — not a loop.
        if (config('queue.default') === 'sync') {
            return;
        }

        if (now()->addSeconds($interval)->timestamp >= $deadline) {
            // The next beat would land after the message. Stopping here leaves
            // the indicator lit for the rest of the pause on its own countdown,
            // which is exactly what is wanted.
            return;
        }

        RefreshFlowPresence::dispatch($flowStateId, $claimKey, $token, $kind, $deadline, $beat + 1)
            ->delay(now()->addSeconds($interval))
            ->onQueue(self::queue());
    }

    /**
     * Take the indicator down now.
     *
     * A real request on the channels that have one and a no-op on the ones that
     * only expire — but API Way has no timeout at all, so on that channel this
     * call is the only thing between the customer and a bot that appears to be
     * typing forever. Called wherever a pause ends without a message following
     * it: a sequence that failed, or one a person interrupted.
     */
    public static function stop(?Conversation $conversation): void
    {
        if (! $conversation || ! $conversation->connection?->channel?->supportsTypingIndicator()) {
            return;
        }

        (new MessageService)->sendTyping($conversation, false);
    }

    /** Whether a pause on this conversation can be filled at all. */
    public static function usable(Conversation $conversation): bool
    {
        return (bool) config('flow.presence.enabled', true)
            && ! $conversation->isGroup()
            && (bool) $conversation->connection?->channel?->supportsTypingIndicator();
    }

    /**
     * The token of whatever currently owns the pausing node, if it is still
     * within its own expiry. Same shape for every claim: `{token, expires_at}`.
     */
    private static function claimToken(FlowState $flowState, string $claimKey): ?string
    {
        $claim = ($flowState->state_data ?? [])[$claimKey] ?? null;

        if (! is_array($claim)) {
            return null;
        }

        if ((int) ($claim['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        $token = $claim['token'] ?? null;

        return is_string($token) ? $token : null;
    }

    public static function maxSeconds(): int
    {
        return max(0, min(
            (int) config('flow.presence.max_seconds', 30),
            self::MAX_WINDOW_CEILING,
        ));
    }

    public static function queue(): string
    {
        $queue = trim((string) config('flow.presence.queue', 'default'));

        return $queue !== '' ? $queue : 'default';
    }

    /**
     * Log a pause that could not be filled, without ever letting that stop the
     * pause itself. Used by callers that wrap the start in a try/catch.
     */
    public static function reportFailure(\Throwable $th, int $flowStateId, string $claimKey): void
    {
        Log::warning('FlowPresence: could not start the indicator for a pause', [
            'flow_state_id' => $flowStateId,
            'claim' => $claimKey,
            'error' => $th->getMessage(),
        ]);
    }
}
