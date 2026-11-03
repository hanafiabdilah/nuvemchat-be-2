<?php

namespace App\Services\Reservations;

use App\Models\ReservableService;
use App\Services\BusinessHours;
use Carbon\CarbonImmutable;

/**
 * A service's opening hours, read and written.
 *
 * Weekly hours are the same JSON the connection's service hours use
 * (`{"mon": [{"open": "09:00", "close": "17:00"}], ...}`), so the dashboard
 * edits both with one component. A day with no ranges is closed.
 *
 * Special dates replace a day's hours: `[{"date": "2026-12-25", "ranges": [],
 * "note": "Christmas"}]` closes Christmas, a list of ranges opens it on other
 * hours, and `"full": true` says the business is open but takes no more
 * bookings that day — the one thing about the business's own bookings an
 * operator may tell the AI, with one click, when they know it.
 *
 * A range may run past midnight: "18:00 → 02:00" is read as closing at 02:00
 * the next morning, "00:00" as a close means midnight — how a bar writes its
 * hours — and a close equal to the open is the whole day. The slots a range
 * yields still belong to the day it opened.
 */
final class ReservationSchedule
{
    public const DAYS = BusinessHours::DAYS;

    /** Past this, a list is a mistake rather than a schedule. */
    private const MAX_RANGES_PER_DAY = 6;

    private const MAX_OVERRIDES = 120;

    /**
     * Clean weekly hours from the dashboard: well-formed ranges only, in
     * order, every day present.
     *
     * @return array<string, list<array{open: string, close: string}>>
     */
    public static function normalizeWeekly(mixed $days): array
    {
        $days = is_array($days) ? $days : [];
        $clean = [];

        foreach (self::DAYS as $day) {
            $clean[$day] = self::normalizeRanges($days[$day] ?? []);
        }

        return $clean;
    }

    /**
     * @return list<array{date: string, ranges: list<array{open: string, close: string}>, note?: string, full?: true}>
     */
    public static function normalizeOverrides(mixed $overrides): array
    {
        $byDate = [];

        foreach (is_array($overrides) ? $overrides : [] as $override) {
            $date = is_array($override) ? (string) ($override['date'] ?? '') : '';

            if (self::parseDate($date) === null) {
                continue;
            }

            $full = filter_var($override['full'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // A full day takes no booking at any hour; its ranges would only
            // be read as "open", so none are kept.
            $entry = ['date' => $date, 'ranges' => $full ? [] : self::normalizeRanges($override['ranges'] ?? [])];
            $note = trim((string) ($override['note'] ?? ''));

            if ($note !== '') {
                $entry['note'] = mb_substr($note, 0, 120);
            }

            if ($full) {
                $entry['full'] = true;
            }

            // The last one written for a date wins, like an edit would.
            $byDate[$date] = $entry;
        }

        ksort($byDate);

        return array_slice(array_values($byDate), 0, self::MAX_OVERRIDES);
    }

    /**
     * The opening ranges of a local date, as minutes from that date's midnight
     * (a close past midnight is above 1440).
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function rangesOn(ReservableService $service, CarbonImmutable $localDate): array
    {
        $date = $localDate->format('Y-m-d');
        $ranges = null;

        foreach ((array) ($service->date_overrides ?? []) as $override) {
            if (($override['date'] ?? null) === $date) {
                $ranges = (array) ($override['ranges'] ?? []);
            }
        }

        if ($ranges === null) {
            $day = self::DAYS[$localDate->dayOfWeekIso - 1];
            $ranges = (array) (($service->weekly_hours ?? [])[$day] ?? []);
        }

        $minutes = [];

        foreach ($ranges as $range) {
            $open = self::toMinutes($range['open'] ?? null);
            $close = self::toMinutes($range['close'] ?? null);

            if ($open === null || $close === null) {
                continue;
            }

            if ($close <= $open) {
                $close += 1440;
            }

            $minutes[] = [$open, $close];
        }

        usort($minutes, fn ($a, $b) => $a[0] <=> $b[0]);

        return $minutes;
    }

    /** Whether a special date marks this local date as fully booked. */
    public static function isFullOn(ReservableService $service, string $date): bool
    {
        foreach ((array) ($service->date_overrides ?? []) as $override) {
            if (($override['date'] ?? null) === $date && ($override['full'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** The special date's note, when that day has one ("Christmas"). */
    public static function overrideNote(ReservableService $service, string $date): ?string
    {
        foreach ((array) ($service->date_overrides ?? []) as $override) {
            if (($override['date'] ?? null) === $date && isset($override['note'])) {
                return (string) $override['note'];
            }
        }

        return null;
    }

    /**
     * "Mon 09:00–17:00; Tue closed; …" — the weekly hours in one line, for
     * the AI. Special dates are read per day (ReservationRules::day).
     */
    public static function summary(ReservableService $service): string
    {
        $labels = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
        $parts = [];

        foreach (self::DAYS as $day) {
            $ranges = (array) (($service->weekly_hours ?? [])[$day] ?? []);
            $parts[] = $labels[$day].' '.($ranges === []
                ? 'closed'
                : collect($ranges)->map(fn ($r) => ($r['open'] ?? '?').'–'.($r['close'] ?? '?'))->implode(', '));
        }

        return implode('; ', $parts);
    }

    /** "2026-10-25" → that date at midnight in the zone, or null when it is not a date. */
    public static function parseDate(string $date, string $timezone = 'UTC'): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, $timezone);
    }

    /**
     * "19:00", "7:30", "19.00", "1900" → minutes from midnight; null when it is
     * not a time of day. "24:00" is midnight at the end of the day.
     */
    public static function parseTime(string $time): ?int
    {
        $time = trim($time);

        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $time, $m) || preg_match('/^(\d{2})(\d{2})$/', $time, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];

            if ($minutes > 59 || $hours > 24 || ($hours === 24 && $minutes > 0)) {
                return null;
            }

            return $hours * 60 + $minutes;
        }

        return null;
    }

    public static function formatMinutes(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @return list<array{open: string, close: string}> */
    private static function normalizeRanges(mixed $ranges): array
    {
        $clean = [];

        foreach (is_array($ranges) ? $ranges : [] as $range) {
            if (! is_array($range)) {
                continue;
            }

            $open = self::toMinutes($range['open'] ?? null);
            $close = self::toMinutes($range['close'] ?? null);

            // open === close is a whole day ("00:00 → 00:00"), not an empty
            // range: rangesOn() reads every close at or before its open as
            // the next morning's.
            if ($open === null || $close === null) {
                continue;
            }

            $clean[] = ['open' => self::formatMinutes($open), 'close' => self::formatMinutes($close)];
        }

        usort($clean, fn ($a, $b) => strcmp($a['open'], $b['open']));

        return array_slice($clean, 0, self::MAX_RANGES_PER_DAY);
    }

    private static function toMinutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }
}
