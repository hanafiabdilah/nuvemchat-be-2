<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The few things every Back Office list reads the same way: comma lists,
 * a day range, and a sort direction.
 *
 * Kept small on purpose. Each list still decides which columns can be
 * filtered and sorted — a generic "sort by any column" would let a query
 * string order by whatever the table happens to hold.
 */
trait AdminListing
{
    /**
     * Turn "a,b" (what the Back Office sends) or a[] into arrays, for the
     * given keys, so `array` validation rules apply to both spellings.
     *
     * @param  list<string>  $keys
     * @return array<string, list<string>>
     */
    protected function listsFrom(Request $request, array $keys): array
    {
        $lists = [];

        foreach ($keys as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $raw = $request->input($key);
            $values = is_array($raw) ? $raw : explode(',', (string) $raw);
            $lists[$key] = array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                $values,
            ), fn ($value) => $value !== ''));
        }

        return $lists;
    }

    /**
     * Inclusive range of calendar days on a datetime column. Either end may
     * be missing; "to" covers the whole of its day.
     */
    protected function whereDayRange($query, string $column, ?string $from, ?string $to): void
    {
        if ($from) {
            $query->where($column, '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to) {
            $query->where($column, '<=', Carbon::parse($to)->endOfDay());
        }
    }

    /**
     * The sort column and direction, limited to $allowed (the first is the
     * default). The two values lists sent before columns were sortable —
     * newest and oldest — still mean the first column, descending/ascending.
     *
     * @param  list<string>  $allowed
     * @return array{0: string, 1: string}
     */
    protected function sortOf(Request $request, array $allowed, string $defaultDir = 'desc'): array
    {
        $sort = (string) $request->query('sort', $allowed[0]);

        if ($sort === 'newest' || $sort === 'oldest') {
            return [$allowed[0], $sort === 'oldest' ? 'asc' : 'desc'];
        }

        return [in_array($sort, $allowed, true) ? $sort : $allowed[0], $this->direction($request, $defaultDir)];
    }

    /** "asc" or "desc"; anything else is the default. */
    protected function direction(Request $request, string $default = 'desc'): string
    {
        $dir = strtolower((string) $request->query('dir', $default));

        return in_array($dir, ['asc', 'desc'], true) ? $dir : $default;
    }

    /** A plain column, empty values last whatever the direction. */
    protected function orderByColumnNullsLast($query, string $column, string $dir): void
    {
        $query->orderByRaw($query->getQuery()->getGrammar()->wrap($column).' is null')
            ->orderBy($column, $dir);
    }

    /**
     * Order by a correlated subquery with empty values last, whatever the
     * direction: a plan's end date sorted ascending should start with the
     * soonest one, not with every customer who has no plan.
     */
    protected function orderBySubqueryNullsLast($query, Builder $subquery, string $dir): void
    {
        $query->orderByRaw('('.$subquery->toSql().') is null', $subquery->getBindings())
            ->orderBy($subquery, $dir);
    }
}
