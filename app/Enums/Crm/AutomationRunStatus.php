<?php

namespace App\Enums\Crm;

/** How one run of an automation went. */
enum AutomationRunStatus: string
{
    case Queued = 'queued';
    /** Every action did what it was asked. */
    case Succeeded = 'succeeded';
    /** Some actions did, some failed or had nothing to act on. */
    case Partial = 'partial';
    /** Nothing it tried worked. */
    case Failed = 'failed';
    /** Did not act: the record changed or went away before it ran, or no longer matched. */
    case Skipped = 'skipped';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
