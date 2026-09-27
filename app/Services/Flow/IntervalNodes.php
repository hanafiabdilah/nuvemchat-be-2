<?php

namespace App\Services\Flow;

/**
 * The Interval node ("Intervalo"): wait, then carry on.
 *
 * Pausing already existed, but only ever *inside* a Message node — a delay
 * attached to a bubble. That covers pacing a sequence and nothing else. It
 * cannot hold the flow between two different nodes: after tagging somebody and
 * before asking the next question, between a menu and the call to an API, or
 * simply to let a long message be read before the one that follows it. Building
 * those meant inventing an empty Message node with a delay and no text — which
 * does not work, because a bubble with nothing in it is skipped at send time.
 *
 * So this node is the pause by itself. One output, nothing sent, nothing
 * stored.
 *
 * ⚠️ Not to be confused with {@see WaitResponseNodes}, which is the other kind
 * of waiting and reads almost the same in a sentence. That one waits for the
 * *customer* and ends when they write; this one waits for the *clock* and ends
 * whether they write or not. Wiring the wrong one is the difference between a
 * flow that listens and a flow that talks over somebody.
 *
 * The frontend mirrors these names and limits in `lib/intervalNodes.ts`.
 */
final class IntervalNodes
{
    /**
     * Longest pause: 24 hours.
     *
     * Not an arbitrary round number. WhatsApp Official refuses free-form
     * content more than 24 hours after the customer's last message, and a flow
     * that came back to life after that would be a node whose send is rejected
     * by the platform — a feature that looks like it works right up until it
     * silently does not. A follow-up a day later is a campaign, and campaigns
     * have their own machinery.
     */
    public const MAX_SECONDS = 86400;

    /**
     * Where a new node starts.
     *
     * Five seconds rather than zero: a node dropped on the canvas should do the
     * thing it is named after while its author decides how long, and a zero
     * would make it a no-op that still costs a queued job.
     */
    public const DEFAULT_SECONDS = 5;

    /** Units the builder offers, in seconds. Display only — the engine reads seconds. */
    public const UNITS = [
        'seconds' => 1,
        'minutes' => 60,
        'hours' => 3600,
    ];

    /**
     * What a new node holds.
     *
     * ⚠️ `presence` is off here and on for a Message node's delay, and the two
     * defaults disagree on purpose. A bubble's pause exists to pace a sequence
     * that is *about to arrive*, so "digitando…" is the whole point of it. An
     * interval is usually the opposite: room for the customer to read, look
     * something up, or go and find their order number. Typing at somebody
     * through that is not pacing, it is hurrying them — and worse, it is a
     * promise of a message that may be a minute away.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'seconds' => self::DEFAULT_SECONDS,
            'unit' => 'seconds',
            'presence' => false,
        ];
    }

    /**
     * How long this node waits, in seconds. Zero means it does not wait at all,
     * which the executor treats as a node to step straight over.
     *
     * @param  array<string, mixed>  $data
     */
    public static function seconds(array $data): int
    {
        $seconds = (int) ($data['seconds'] ?? 0);

        return max(0, min($seconds, self::MAX_SECONDS));
    }

    /**
     * Whether the wait is shown to the customer as "digitando…".
     *
     * @param  array<string, mixed>  $data
     */
    public static function presenceEnabled(array $data): bool
    {
        return (bool) ($data['presence'] ?? false);
    }

    /**
     * The largest unit that divides the wait exactly, so a node written as
     * "2 hours" comes back reading 2 hours rather than 7200 seconds.
     */
    public static function unitFor(int $seconds): string
    {
        foreach (['hours', 'minutes'] as $unit) {
            if ($seconds > 0 && $seconds % self::UNITS[$unit] === 0) {
                return $unit;
            }
        }

        return 'seconds';
    }

    /**
     * The claim on a waiting node: `{token, resume_at, expires_at}`, the same
     * shape the message chain uses, so {@see FlowPresence} can validate either
     * without knowing which it is looking at.
     *
     * Read by the panel's cold-load reading too (`lib/conversationActivity.ts`),
     * which is how a conversation parked here still shows as pausing after a
     * page reload.
     */
    public static function claimKey(int $nodeId): string
    {
        return "_interval_{$nodeId}";
    }

    /**
     * How long a claim is believed.
     *
     * The wait plus a grace margin: a job that never ran must eventually stop
     * blocking the node, or a flow that came back round to it would sit on a
     * pause nobody is counting down.
     */
    public const CLAIM_GRACE_SECONDS = 300;
}
