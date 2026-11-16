<?php

namespace App\Enums\Billing;

/**
 * How many charges a coupon discounts.
 *
 * For a plan coupon it counts cycles: `once` is the checkout charge only,
 * `repeating` is the first N cycles (the checkout being the first), `forever`
 * is every cycle while the subscription lasts. A top-up coupon is always
 * `once`: it applies to the top-up it is typed on — with no limit on how many
 * top-ups one workspace uses it for (only the coupon's own quota).
 */
enum CouponDuration: string
{
    case Once = 'once';
    case Repeating = 'repeating';
    case Forever = 'forever';

    /** Cycles discounted, or null when there is no end. */
    public function cycles(?int $repeatingCycles): ?int
    {
        return match ($this) {
            self::Once => 1,
            self::Repeating => max(1, (int) $repeatingCycles),
            self::Forever => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $duration) => $duration->value, self::cases());
    }
}
