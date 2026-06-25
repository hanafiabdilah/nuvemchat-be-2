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

    public static function monthExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";
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
