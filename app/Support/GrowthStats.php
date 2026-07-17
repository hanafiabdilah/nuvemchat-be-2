<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds 12-month cumulative growth series for any model with `created_at`.
 * Shared by the dashboard (/admin/stats) and the analytics (/admin/statistics).
 */
class GrowthStats
{
    public static function months(): Collection
    {
        return collect(range(11, 0))
            ->map(fn ($i) => Carbon::now()->startOfMonth()->subMonths($i));
    }

    /**
     * Month bucket expression for a timestamp column. Revenue buckets on `paid_at`
     * rather than `created_at`, hence the parameter. The sqlite branch keeps the
     * test suite working — production is MySQL.
     */
    public static function monthExpr(string $column = 'created_at'): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /**
     * @param  Closure():\Illuminate\Database\Eloquent\Builder  $newQuery
     * @return array<int, array{period:string, new:int, total:int}>
     */
    public static function cumulative(Closure $newQuery): array
    {
        $months = self::months();
        $start = $months->first();
        $expr = self::monthExpr();

        $byMonth = $newQuery()
            ->where('created_at', '>=', $start)
            ->selectRaw("$expr as period, COUNT(*) as c")
            ->groupBy('period')
            ->pluck('c', 'period');

        $running = $newQuery()->where('created_at', '<', $start)->count();

        return $months->map(function ($m) use ($byMonth, &$running) {
            $key = $m->format('Y-m');
            $new = (int) ($byMonth[$key] ?? 0);
            $running += $new;
            return ['period' => $key, 'new' => $new, 'total' => $running];
        })->values()->all();
    }
}
