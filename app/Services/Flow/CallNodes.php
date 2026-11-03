<?php

namespace App\Services\Flow;

/**
 * The Call node ("Ligação"): ring the customer's WhatsApp from the business's
 * number, optionally play a recording when they pick up, then carry on.
 *
 * A nudge rather than a conversation. This platform has no voice stack — it
 * cannot hear the customer or talk back — so the call is the ring itself, plus
 * at most a recording the API Way core plays down the line. What it is for is
 * the missed-call notification: a chat with a call in it jumps to the top of
 * the customer's list, and the message the flow sends next is read.
 *
 * API Way only (`Channel::supportsCalls()`): the core places the call through
 * its own endpoint (`POST /v1/call/make`). WhatsApp Official can only call a
 * customer who granted permission first, with a real-time media stack behind
 * it, which is not what this node is. On any other channel the node is stepped
 * over — the same flow keeps working wherever it runs.
 *
 * One output. The flow waits for the call to end — the core's result event, or
 * failing that the longest the call could have lasted — and then moves on.
 * Whether the customer answered lands in {{call_status}} for a Condition node
 * to read; the node itself does not branch on it.
 *
 * The frontend mirrors these names and limits in `lib/callNodes.ts`.
 */
final class CallNodes
{
    /** Shortest and longest ring the builder offers, in seconds. */
    public const MIN_RING_SECONDS = 1;

    public const MAX_RING_SECONDS = 15;

    /** Where a new node starts. */
    public const DEFAULT_RING_SECONDS = 8;

    public const MAX_AUDIO_URL_LENGTH = 2048;

    /**
     * Longest the flow waits on one call, whatever the core says the call can
     * last: a recording an hour long would otherwise park a funnel for an hour.
     */
    public const MAX_WAIT_SECONDS = 600;

    /**
     * How long the flow waits on a call with a recording when the core did not
     * say how long the recording is. Long enough for a typical voice message.
     */
    public const AUDIO_FALLBACK_SECONDS = 60;

    /**
     * Added to the wait so the core's result event, which arrives a moment
     * after the call ends, normally gets there before the timer does — and
     * {{call_status}} says what happened instead of "unknown".
     */
    public const RESULT_GRACE_SECONDS = 5;

    /**
     * How long a claim is believed past its wait: a job that never ran must
     * eventually stop blocking the node.
     */
    public const CLAIM_GRACE_SECONDS = 300;

    /** The flow variable that says how the call went. */
    public const VARIABLE = 'call_status';

    /** The call is ringing; the flow is waiting on it. */
    public const STATUS_RINGING = 'ringing';

    /** The customer picked up. */
    public const STATUS_ANSWERED = 'answered';

    /** It rang out. */
    public const STATUS_NO_ANSWER = 'no_answer';

    /** The customer declined it. */
    public const STATUS_REJECTED = 'rejected';

    /** The core refused to place it, or WhatsApp dropped it. */
    public const STATUS_FAILED = 'failed';

    /** The flow moved on without hearing how the call ended. */
    public const STATUS_UNKNOWN = 'unknown';

    /** The connection cannot place calls (not API Way, or calls switched off). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUSES = [
        self::STATUS_RINGING,
        self::STATUS_ANSWERED,
        self::STATUS_NO_ANSWER,
        self::STATUS_REJECTED,
        self::STATUS_FAILED,
        self::STATUS_UNKNOWN,
        self::STATUS_UNAVAILABLE,
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'ring_seconds' => self::DEFAULT_RING_SECONDS,
            // A recording played when the customer answers; null = just the ring.
            // A URL, possibly with {{variables}} in it.
            'audio_url' => null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function ringSeconds(array $data): int
    {
        $seconds = (int) ($data['ring_seconds'] ?? self::DEFAULT_RING_SECONDS);

        return max(self::MIN_RING_SECONDS, min(self::MAX_RING_SECONDS, $seconds));
    }

    /**
     * The recording's address as written (variables not yet resolved), or null
     * for a call that only rings.
     *
     * @param  array<string, mixed>  $data
     */
    public static function audioUrl(array $data): ?string
    {
        $url = trim((string) ($data['audio_url'] ?? ''));

        return $url === '' ? null : $url;
    }

    /**
     * How long the flow waits on a placed call before moving on by itself.
     *
     * The core knows how long the call can run (the ring, plus the recording
     * when there is one) and says so when it accepts the call. Without that,
     * a call that only rings is over at the ring, and one with a recording gets
     * a voice message's worth of time.
     */
    public static function waitSeconds(int $ringSeconds, ?int $coreMaxSeconds, bool $withAudio): int
    {
        $expected = $coreMaxSeconds !== null && $coreMaxSeconds > 0
            ? max($ringSeconds, $coreMaxSeconds)
            : ($withAudio ? $ringSeconds + self::AUDIO_FALLBACK_SECONDS : $ringSeconds);

        return min(self::MAX_WAIT_SECONDS, $expected) + self::RESULT_GRACE_SECONDS;
    }

    /**
     * The core's word for how a call ended, in ours. Anything unrecognised is
     * a failure rather than a guess at an answer.
     */
    public static function statusFromCore(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'answered', 'accepted', 'completed' => self::STATUS_ANSWERED,
            'no_answer', 'not_answered', 'missed', 'timeout' => self::STATUS_NO_ANSWER,
            'rejected', 'declined', 'busy' => self::STATUS_REJECTED,
            default => self::STATUS_FAILED,
        };
    }

    /**
     * The claim on a node waiting for its call: `{token, call_id, resume_at,
     * expires_at}` — the interval's shape plus the call it is waiting on, which
     * is how the core's result event finds the flow that placed the call.
     */
    public static function claimKey(int $nodeId): string
    {
        return "_call_{$nodeId}";
    }
}
