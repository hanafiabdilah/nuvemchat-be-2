<?php

namespace App\Enums\Crm;

/**
 * What people log themselves on a timeline. System entries (created, stage
 * changed…) are CrmTimeline constants, not these: they are written by the
 * code, and nobody edits or deletes them.
 */
enum ActivityType: string
{
    case Note = 'note';
    case Call = 'call';
    case Meeting = 'meeting';
    case Email = 'email';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
