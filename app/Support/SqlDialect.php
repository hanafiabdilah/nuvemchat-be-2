<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The handful of SQL expressions the statistics queries need that MySQL and
 * SQLite spell differently. Production is MySQL; the test suite is SQLite, and
 * the previous statistics endpoints used raw HOUR()/TIMESTAMPDIFF() and so
 * could not be tested at all.
 *
 * Timezone: every timestamp in the database is UTC, but "peak hour" and "per
 * day" only mean something in the viewer's clock, so each bucketing helper
 * takes an offset in seconds and shifts the column by it. A fixed offset (not
 * a named zone) is deliberate — MySQL's CONVERT_TZ needs the timezone tables
 * loaded, which no deployment here guarantees. Brazil has had no DST since
 * 2019, so a fixed offset is exact for the audience this serves; elsewhere a
 * range spanning a DST change is off by an hour at the seam.
 */
class SqlDialect
{
    public static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** The column shifted into the viewer's timezone. */
    public static function shifted(string $column, int $offsetSeconds): string
    {
        $offsetSeconds = (int) $offsetSeconds;

        if ($offsetSeconds === 0) {
            return $column;
        }

        if (self::isSqlite()) {
            $sign = $offsetSeconds > 0 ? '+' : '-';

            return "datetime({$column}, '{$sign}" . abs($offsetSeconds) . " seconds')";
        }

        return "({$column} + INTERVAL {$offsetSeconds} SECOND)";
    }

    /** Hour of day, 0–23. */
    public static function hour(string $column, int $offsetSeconds = 0): string
    {
        $shifted = self::shifted($column, $offsetSeconds);

        return self::isSqlite()
            ? "CAST(strftime('%H', {$shifted}) AS INTEGER)"
            : "HOUR({$shifted})";
    }

    /** Calendar day as 'YYYY-MM-DD'. */
    public static function date(string $column, int $offsetSeconds = 0): string
    {
        $shifted = self::shifted($column, $offsetSeconds);

        return self::isSqlite()
            ? "strftime('%Y-%m-%d', {$shifted})"
            : "DATE_FORMAT({$shifted}, '%Y-%m-%d')";
    }

    /** Day of week, 0 = Sunday .. 6 = Saturday (both drivers normalised). */
    public static function dayOfWeek(string $column, int $offsetSeconds = 0): string
    {
        $shifted = self::shifted($column, $offsetSeconds);

        return self::isSqlite()
            ? "CAST(strftime('%w', {$shifted}) AS INTEGER)"
            : "(DAYOFWEEK({$shifted}) - 1)";
    }

    /** Whole seconds from $start to $end (negative when $end is earlier). */
    public static function diffSeconds(string $start, string $end): string
    {
        return self::isSqlite()
            ? "(CAST(strftime('%s', {$end}) AS INTEGER) - CAST(strftime('%s', {$start}) AS INTEGER))"
            : "TIMESTAMPDIFF(SECOND, {$start}, {$end})";
    }
}
