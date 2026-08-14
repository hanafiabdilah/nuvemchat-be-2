<?php

namespace App\Services\Statistics;

use Illuminate\Database\Query\Builder;

/**
 * Percentiles read straight out of the database by ordering and skipping,
 * rather than by pulling every duration into PHP.
 *
 * Medians, not averages, are what these pages report: a single conversation
 * answered three days late moves a mean far more than it moves anyone's actual
 * experience of the queue.
 */
class Percentiles
{
    /**
     * @param  Builder  $query  a query whose rows are the population
     * @param  string  $column  the numeric column to rank by
     * @param  int  $count  how many rows that query has (already counted)
     */
    public static function at(Builder $query, string $column, int $count, float $percentile): ?int
    {
        if ($count <= 0) {
            return null;
        }

        $offset = max(0, (int) ceil(($percentile / 100) * $count) - 1);

        $value = (clone $query)
            ->orderBy($column)
            ->offset($offset)
            ->limit(1)
            ->value($column);

        return $value === null ? null : (int) round((float) $value);
    }

    public static function median(Builder $query, string $column, int $count): ?int
    {
        return self::at($query, $column, $count, 50);
    }
}
