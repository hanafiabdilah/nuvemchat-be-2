<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Per-tenant service (business) hours. Used to gate AI → human handoff: a human
 * is only offered while the tenant is "open"; outside those hours the AI keeps
 * handling the conversation.
 *
 * Shape stored in `tenants.service_hours` (JSON):
 *   {
 *     "enabled": true,
 *     "timezone": "America/Sao_Paulo",
 *     "days": { "mon": [{ "open": "08:00", "close": "22:00" }], ... },
 *     "away_message": "..."
 *   }
 */
class BusinessHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Is the tenant currently within its service hours?
     *
     * When service hours are not configured or disabled, the tenant is treated
     * as always open (handoff to a human is always allowed) — opting in is the
     * explicit action.
     */
    public static function isOpen(Tenant $tenant, ?Carbon $now = null): bool
    {
        $config = $tenant->service_hours;

        if (empty($config) || empty($config['enabled'])) {
            return true;
        }

        $tz = $config['timezone'] ?? config('app.timezone', 'UTC');
        $now = ($now ? $now->copy() : Carbon::now())->setTimezone($tz);

        $dayKey = self::DAYS[$now->dayOfWeekIso - 1]; // 1 (Mon) .. 7 (Sun)
        $ranges = $config['days'][$dayKey] ?? [];

        $minutes = $now->hour * 60 + $now->minute;

        foreach ($ranges as $range) {
            $open = self::toMinutes($range['open'] ?? null);
            $close = self::toMinutes($range['close'] ?? null);

            if ($open === null || $close === null) {
                continue;
            }

            if ($minutes >= $open && $minutes < $close) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message to send when a contact reaches out (or asks for a human)
     * outside service hours. Null when not configured.
     */
    public static function awayMessage(Tenant $tenant): ?string
    {
        $message = $tenant->service_hours['away_message'] ?? null;

        return is_string($message) && trim($message) !== '' ? $message : null;
    }

    /**
     * A sensible default config used by the settings API when nothing is set yet.
     */
    public static function defaultConfig(): array
    {
        $days = [];
        foreach (self::DAYS as $day) {
            // Weekdays open 08:00–22:00 by default; weekend closed.
            $days[$day] = in_array($day, ['sat', 'sun'], true)
                ? []
                : [['open' => '08:00', 'close' => '22:00']];
        }

        return [
            'enabled' => false,
            'timezone' => config('app.timezone', 'America/Sao_Paulo'),
            'days' => $days,
            'away_message' => '',
        ];
    }

    /** Parse "HH:MM" into minutes-since-midnight, or null when malformed. */
    private static function toMinutes(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];

        if ($h > 23 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
    }
}
