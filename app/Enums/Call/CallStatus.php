<?php

namespace App\Enums\Call;

/**
 * Where a call answered from the dashboard has got to.
 *
 *   ringing → connecting → ongoing → ended
 *      ↘ missed / rejected     ↘ failed
 *
 * `connecting` is the stretch between an agent pressing Answer and Meta saying
 * yes: the call is already theirs (nobody else may claim it, their phone stops
 * ringing for others) but not yet a conversation anyone can hear.
 */
enum CallStatus: string
{
    case Ringing = 'ringing';
    case Connecting = 'connecting';
    case Ongoing = 'ongoing';
    case Ended = 'ended';
    case Missed = 'missed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    /** Still happening — what the "who is on a call" indicators show. */
    public function isLive(): bool
    {
        return in_array($this, [self::Ringing, self::Connecting, self::Ongoing], true);
    }

    /** Holding the agent who claimed it: they are not rung for anything else. */
    public function occupiesAgent(): bool
    {
        return in_array($this, [self::Connecting, self::Ongoing], true);
    }

    /** @return array<int, string> */
    public static function liveValues(): array
    {
        return [self::Ringing->value, self::Connecting->value, self::Ongoing->value];
    }

    /** @return array<int, string> */
    public static function occupyingValues(): array
    {
        return [self::Connecting->value, self::Ongoing->value];
    }
}
