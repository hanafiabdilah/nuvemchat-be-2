<?php

namespace App\Services\AiAgentHub;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Jobs\RefreshAiTypingIndicator;
use App\Models\Conversation;
use App\Services\Message\MessageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "digitando…" shown to the customer for as long as the AI is busy.
 *
 * The composer already does this for a human agent, driven by their keystrokes.
 * Nobody is typing here, so the tempo has to come from somewhere else — and it
 * cannot simply be asserted once, because every one of these indicators is a
 * dead man's switch: the platform lights it and starts counting down (Telegram
 * 4s, Meta and API Way ~10s, and API Way not at all, which is the one channel
 * where forgetting to stop leaves a ghost typing forever).
 *
 * So an episode is a token in the cache plus a job that re-asserts the
 * indicator until that token is gone. It starts when the turn is armed, not
 * when the hub is called: the debounce window is the silence the customer
 * notices first, and covering only the hub round-trip would leave the part this
 * was asked for untouched.
 *
 * Everything here is best-effort and silent, like MessageService::sendTyping
 * itself. A decoration must never be the reason a reply does not go out.
 */
class AiTypingPresence
{
    /**
     * Ceiling on one episode, whatever the config says.
     *
     * The job re-queues itself, so this is the thing standing between a stuck
     * conversation and an indicator that refreshes for the rest of the day.
     */
    private const MAX_SECONDS_CEILING = 600;

    /**
     * Hard stop on the number of beats in one episode, whatever the clock says.
     *
     * The deadline is the real limit; this is the belt to its braces, and it
     * covers the one case the deadline cannot: a queue where time does not move
     * between a job and the job it queues. A self-dispatching job with only a
     * wall-clock stop is one frozen clock away from running forever.
     */
    private const MAX_BEATS = 200;

    public static function key(int $conversationId): string
    {
        return "ai-typing:{$conversationId}";
    }

    /**
     * Begin showing it, unless an episode is already running.
     *
     * `Cache::add` rather than `put` is what makes a burst cheap: five messages
     * re-arm the turn five times, and only the first starts a refresher — the
     * other four find the token already there and leave the one that is running
     * alone.
     */
    public static function start(Conversation $conversation): void
    {
        if (! self::usable($conversation)) {
            return;
        }

        $token = (string) Str::uuid();
        $seconds = self::maxSeconds();

        // Outlives the episode by a margin: a token that expires while the
        // refresher is still queued would let a second episode start alongside
        // the first, and both would keep asserting.
        if (! Cache::add(self::key($conversation->id), $token, $seconds + 60)) {
            return;
        }

        RefreshAiTypingIndicator::dispatch(
            $conversation->id,
            $token,
            now()->addSeconds($seconds)->timestamp,
        )->onQueue(AiHoldingMessage::queue());
    }

    /**
     * One beat of the episode: assert the indicator and queue the next beat.
     *
     * Returns quietly whenever the episode is no longer this one's — a newer
     * episode, a conversation somebody took over, a channel that stopped
     * accepting it. The refresher simply stops; there is nothing to report.
     */
    public static function refresh(int $conversationId, string $token, int $deadline, int $beat = 1): void
    {
        if (Cache::get(self::key($conversationId)) !== $token) {
            return;
        }

        if ($beat > self::MAX_BEATS) {
            self::clear($conversationId);

            return;
        }

        $conversation = Conversation::with('connection')->find($conversationId);

        if (! $conversation || ! self::usable($conversation)) {
            self::clear($conversationId);

            return;
        }

        // A person took the thread, or it was resolved, while we were queued.
        // Withdraw rather than leave the customer watching a bot that is no
        // longer answering them.
        if (! in_array($conversation->status, ConversationStatus::flowEligible(), true)) {
            self::stop($conversation);

            return;
        }

        if (now()->timestamp >= $deadline) {
            // The turn has outlived anything a customer would read as "typing".
            // Ending the episode also frees the token, so the next armed turn
            // can start a fresh one.
            self::stop($conversation);

            Log::info('AiTypingPresence: episode ended at its deadline', [
                'conversation_id' => $conversationId,
            ]);

            return;
        }

        (new MessageService)->sendTyping($conversation, true);

        $interval = $conversation->connection?->channel?->typingRefreshSeconds();

        if (! $interval) {
            self::clear($conversationId);

            return;
        }

        // On a `sync` queue there is no later: a delayed dispatch runs now, so
        // a refresher would re-enter itself immediately and forever. The honest
        // behaviour there is a single beat — an indicator that appears once and
        // expires on the channel's own countdown — not a loop.
        if (self::queueRunsInline()) {
            self::clear($conversationId);

            return;
        }

        RefreshAiTypingIndicator::dispatch($conversationId, $token, $deadline, $beat + 1)
            ->delay(now()->addSeconds($interval))
            ->onQueue(AiHoldingMessage::queue());
    }

    /**
     * End the episode and take the indicator down.
     *
     * The withdrawal is a real request on the channels that have one and a
     * no-op on the ones that only expire — but API Way has no timeout at all,
     * so on that channel this call is the only thing between the customer and
     * an agent who appears to be typing forever.
     */
    public static function stop(Conversation $conversation): void
    {
        if (! self::clear($conversation->id)) {
            return;
        }

        if (! $conversation->connection?->channel?->supportsTypingIndicator()) {
            return;
        }

        (new MessageService)->sendTyping($conversation, false);
    }

    /** @return bool whether an episode was actually running */
    private static function clear(int $conversationId): bool
    {
        return Cache::pull(self::key($conversationId)) !== null;
    }

    private static function usable(Conversation $conversation): bool
    {
        return (bool) config('ai.typing.enabled', true)
            && ! $conversation->isGroup()
            && (bool) $conversation->connection?->channel?->supportsTypingIndicator();
    }

    /**
     * Whether queued work runs in the caller rather than later.
     *
     * Not a test accommodation: a deployment on the `sync` driver genuinely has
     * no scheduler behind it, and everything in this class is built on "come
     * back in a few seconds".
     */
    private static function queueRunsInline(): bool
    {
        return config('queue.default') === 'sync';
    }

    private static function maxSeconds(): int
    {
        return max(10, min(
            (int) config('ai.typing.max_seconds', 180),
            self::MAX_SECONDS_CEILING,
        ));
    }
}
