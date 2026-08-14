<?php

namespace App\Services\Statistics;

use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Services\BusinessHours;
use Illuminate\Support\Facades\DB;

/**
 * How well the queue was served: how fast the first answer came, how often it
 * came within the target, where conversations fall out of the funnel, when the
 * AI gave up and asked for a person, and how much of the traffic arrives while
 * the doors are shut.
 *
 * Durations are reported as a distribution rather than a single average. "The
 * average reply took 14 minutes" is unreadable; "62% within a minute, 9% took
 * over two hours" is a decision.
 */
class ServiceStats
{
    /** Upper edges, in seconds, of the first-response histogram. */
    private const BUCKETS = [60, 300, 1800, 7200];

    private readonly ConversationTimings $timings;

    public function __construct(
        private readonly StatsScope $scope,
        private readonly int $slaMinutes = 10,
    ) {
        $this->timings = new ConversationTimings($scope);
    }

    public function build(): array
    {
        return [
            'sla_minutes' => $this->slaMinutes,
            'response_buckets' => $this->responseBuckets(),
            'sla' => $this->sla(),
            'funnel' => $this->funnel(),
            'handoffs' => $this->handoffs(),
            'service_hours' => $this->serviceHours(),
        ];
    }

    private function responseBuckets(): array
    {
        [$b1, $b2, $b3, $b4] = self::BUCKETS;

        $row = DB::query()
            ->fromSub($this->timings->answered($this->scope->from, $this->scope->to), 'a')
            ->selectRaw(implode(', ', [
                "SUM(CASE WHEN response_seconds <= {$b1} THEN 1 ELSE 0 END) as b1",
                "SUM(CASE WHEN response_seconds > {$b1} AND response_seconds <= {$b2} THEN 1 ELSE 0 END) as b2",
                "SUM(CASE WHEN response_seconds > {$b2} AND response_seconds <= {$b3} THEN 1 ELSE 0 END) as b3",
                "SUM(CASE WHEN response_seconds > {$b3} AND response_seconds <= {$b4} THEN 1 ELSE 0 END) as b4",
                "SUM(CASE WHEN response_seconds > {$b4} THEN 1 ELSE 0 END) as b5",
                'COUNT(*) as total',
            ]))
            ->first();

        // Conversations the customer opened and nobody ever answered — the
        // honest last bar of this chart, and the one worth acting on.
        $unanswered = DB::query()
            ->fromSub($this->timings->query($this->scope->from, $this->scope->to), 'r')
            ->whereNotNull('r.first_in_at')
            ->where('r.has_outgoing', 0)
            ->count();

        return [
            'buckets' => [
                ['key' => 'under_1m', 'max_seconds' => $b1, 'total' => (int) ($row->b1 ?? 0)],
                ['key' => '1m_5m', 'max_seconds' => $b2, 'total' => (int) ($row->b2 ?? 0)],
                ['key' => '5m_30m', 'max_seconds' => $b3, 'total' => (int) ($row->b3 ?? 0)],
                ['key' => '30m_2h', 'max_seconds' => $b4, 'total' => (int) ($row->b4 ?? 0)],
                ['key' => 'over_2h', 'max_seconds' => null, 'total' => (int) ($row->b5 ?? 0)],
            ],
            'answered' => (int) ($row->total ?? 0),
            'never_answered' => $unanswered,
        ];
    }

    private function sla(): array
    {
        $threshold = $this->slaMinutes * 60;

        $row = DB::query()
            ->fromSub($this->timings->answered($this->scope->from, $this->scope->to), 'a')
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN response_seconds <= {$threshold} THEN 1 ELSE 0 END) as within")
            ->first();

        $total = (int) ($row->total ?? 0);
        $within = (int) ($row->within ?? 0);

        return [
            'total' => $total,
            'within' => $within,
            'rate' => $total > 0 ? round(($within / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Where conversations stop. Each stage is a subset of the one before it,
     * so the drop between two bars is a real loss and not a coincidence of
     * different denominators.
     */
    private function funnel(): array
    {
        $row = DB::query()
            ->fromSub($this->timings->query($this->scope->from, $this->scope->to), 'r')
            ->whereNotNull('r.first_in_at')
            ->selectRaw(implode(', ', [
                'COUNT(*) as received',
                'SUM(CASE WHEN has_outgoing = 1 THEN 1 ELSE 0 END) as answered',
                'SUM(CASE WHEN has_outgoing = 1 AND user_id IS NOT NULL THEN 1 ELSE 0 END) as assigned',
                "SUM(CASE WHEN has_outgoing = 1 AND user_id IS NOT NULL AND status = '" . Status::Resolved->value . "' THEN 1 ELSE 0 END) as resolved",
            ]))
            ->first();

        return [
            'received' => (int) ($row->received ?? 0),
            'answered' => (int) ($row->answered ?? 0),
            'assigned' => (int) ($row->assigned ?? 0),
            'resolved' => (int) ($row->resolved ?? 0),
        ];
    }

    /**
     * Every time the AI handed a conversation back to a person, grouped by the
     * reason it gave. The data has been recorded since handoff shipped and has
     * never been shown anywhere.
     */
    private function handoffs(): array
    {
        $rows = $this->scope->conversations()
            ->whereBetween('conversations.handoff_at', [$this->scope->from, $this->scope->to])
            ->groupBy('conversations.handoff_reason')
            ->select('conversations.handoff_reason as reason', DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')
            ->get();

        return [
            'total' => (int) $rows->sum('total'),
            'reasons' => $rows->map(fn ($row) => [
                'reason' => $row->reason ?: 'unspecified',
                'total' => (int) $row->total,
            ])->all(),
        ];
    }

    /**
     * Inbound traffic inside vs. outside each connection's configured service
     * hours. Counted per connection, because the hours are set per connection
     * and one tenant may run two businesses on different schedules.
     *
     * The grid is cut in the viewer's timezone while the schedule is written in
     * the connection's; when the two differ the boundary hours are attributed
     * to the viewer's clock. Both are normally the same zone.
     */
    private function serviceHours(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->where('messages.sender_type', SenderType::Incoming->value)
            ->groupBy(
                'conversations.connection_id',
                DB::raw($this->scope->dayOfWeek('messages.created_at')),
                DB::raw($this->scope->hour('messages.created_at'))
            )
            ->select(
                'conversations.connection_id',
                DB::raw($this->scope->dayOfWeek('messages.created_at') . ' as dow'),
                DB::raw($this->scope->hour('messages.created_at') . ' as hour'),
                DB::raw('COUNT(*) as total')
            )
            ->get();

        if ($rows->isEmpty()) {
            return ['configured' => false, 'inside' => 0, 'outside' => 0, 'rate' => 0.0];
        }

        $connections = Connection::whereIn('id', $rows->pluck('connection_id')->unique())->get()->keyBy('id');
        $configured = $connections->contains(fn ($connection) => ! empty($connection->service_hours['enabled']));

        $grids = [];
        foreach ($connections as $connection) {
            $grids[$connection->id] = $this->openGrid($connection);
        }

        $inside = 0;
        $outside = 0;

        foreach ($rows as $row) {
            $open = $grids[$row->connection_id][(int) $row->dow][(int) $row->hour] ?? true;
            $open ? $inside += (int) $row->total : $outside += (int) $row->total;
        }

        $total = $inside + $outside;

        return [
            'configured' => $configured,
            'inside' => $inside,
            'outside' => $outside,
            'rate' => $total > 0 ? round(($outside / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * A connection's opening hours as a 7 × 24 boolean grid (dow 0 = Sunday),
     * so classifying a bucket is a lookup instead of a date calculation.
     *
     * @return array<int, array<int, bool>>
     */
    private function openGrid(Connection $connection): array
    {
        $config = $connection->service_hours;

        if (empty($config) || empty($config['enabled'])) {
            return []; // Never configured: everything counts as inside hours.
        }

        $grid = [];
        for ($dow = 0; $dow < 7; $dow++) {
            // SQL gives 0 = Sunday; BusinessHours keys Monday first.
            $dayKey = BusinessHours::DAYS[($dow + 6) % 7];
            $ranges = $config['days'][$dayKey] ?? [];

            for ($hour = 0; $hour < 24; $hour++) {
                $minutes = $hour * 60 + 30; // Middle of the hour.
                $open = false;

                foreach ($ranges as $range) {
                    $start = $this->toMinutes($range['open'] ?? null);
                    $end = $this->toMinutes($range['close'] ?? null);

                    if ($start !== null && $end !== null && $minutes >= $start && $minutes < $end) {
                        $open = true;
                        break;
                    }
                }

                $grid[$dow][$hour] = $open;
            }
        }

        return $grid;
    }

    private function toMinutes(?string $time): ?int
    {
        if (! $time || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }
}
