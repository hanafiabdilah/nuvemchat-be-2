<?php

namespace App\Services\Affiliates;

use App\Models\Setting;

/**
 * Platform-wide affiliate settings: edited in the Back Office, read here, with
 * config/affiliates.php as the default.
 */
class AffiliateSettings
{
    public const KEY_HOLD_DAYS = 'affiliates.hold_days';

    public const MAX_HOLD_DAYS = 180;

    /** Days a commission waits before it can be paid out. */
    public static function holdDays(): int
    {
        $value = Setting::get(self::KEY_HOLD_DAYS);

        return is_numeric($value)
            ? max(0, min(self::MAX_HOLD_DAYS, (int) $value))
            : (int) config('affiliates.hold_days', 30);
    }

    public static function setHoldDays(int $days): void
    {
        Setting::set(self::KEY_HOLD_DAYS, (string) max(0, min(self::MAX_HOLD_DAYS, $days)));
    }
}
