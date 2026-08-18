<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The date range a Back Office page is looking at.
 *
 * Every platform-analytics endpoint takes the same `?days=` (or explicit
 * `from`/`to`) and every one of them also wants the preceding window of equal
 * length to show a delta, so both live here rather than being re-derived — and
 * mis-derived — per controller.
 */
class AdminPeriod
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly int $days,
    ) {}

    public static function fromRequest(Request $request, int $default = 30): self
    {
        $days = (int) $request->integer('days', $default);
        $days = max(1, min($days, 365));

        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::now();

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : (clone $to)->subDays($days)->startOfDay();

        return new self($from, $to, max(1, (int) $from->diffInDays($to)));
    }

    /** The window of equal length immediately before this one. */
    public function previous(): self
    {
        $length = $this->from->diffInSeconds($this->to);

        return new self(
            (clone $this->from)->subSeconds($length),
            clone $this->from,
            $this->days,
        );
    }

    /** Whether the range is short enough that a per-day series is readable. */
    public function bucketsByDay(): bool
    {
        return $this->days <= 92;
    }

    /**
     * Every bucket in the range, seeded so gaps render as zero instead of
     * vanishing — a chart that silently skips empty days reads as a smooth
     * trend when it is actually an outage.
     *
     * @return list<string>
     */
    public function buckets(int $offsetSeconds = 0): array
    {
        $out = [];
        $cursor = (clone $this->from)->addSeconds($offsetSeconds);
        $end = (clone $this->to)->addSeconds($offsetSeconds);

        if ($this->bucketsByDay()) {
            $cursor = $cursor->startOfDay();
            while ($cursor <= $end) {
                $out[] = $cursor->format('Y-m-d');
                $cursor = $cursor->addDay();
            }

            return $out;
        }

        $cursor = $cursor->startOfMonth();
        while ($cursor <= $end) {
            $out[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $out;
    }

    /** The SQL bucket expression matching {@see buckets()}. */
    public function bucketExpr(string $column, int $offsetSeconds = 0): string
    {
        return $this->bucketsByDay()
            ? SqlDialect::date($column, $offsetSeconds)
            : SqlDialect::month($column, $offsetSeconds);
    }
}
