<?php

namespace App\Enums\Affiliate;

/**
 * How long an affiliator earns from a workspace they brought in.
 *
 * `once` pays on that workspace's first paid plan charge only. `recurring`
 * pays on every paid plan charge, for `affiliates.recurring_months` from the
 * first one — or for as long as the workspace pays, when that is empty.
 */
enum CommissionType: string
{
    case Once = 'once';
    case Recurring = 'recurring';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
