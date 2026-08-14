<?php

namespace App\Services\Statistics;

use App\Enums\Message\SenderType;
use Illuminate\Support\Facades\DB;

/**
 * Shape of the traffic: how it moved day by day, which hours of which days it
 * arrives in, and where it comes from.
 *
 * The old page drew a flat 0–23 profile in UTC, which for a Brazilian tenant
 * put the lunchtime peak at 3 in the afternoon and hid the difference between
 * a Tuesday and a Sunday. Both are fixed here: every bucket is cut in the
 * viewer's timezone, and the hourly view is a day × hour grid.
 */
class VolumeStats
{
    public function __construct(private readonly StatsScope $scope) {}

    public function build(): array
    {
        return [
            'daily' => $this->daily(),
            'heatmap' => $this->heatmap(),
            'channels' => $this->byChannel(),
            'connections' => $this->byConnection(),
            'message_types' => $this->messageTypes(),
        ];
    }

    /**
     * One row per calendar day, with the previous period aligned to the same
     * index so the client can overlay them without doing date arithmetic.
     */
    private function daily(): array
    {
        $days = $this->scope->days();

        $conversations = $this->countByDay(
            $this->scope->conversations($this->scope->from, $this->scope->to),
            'conversations.created_at'
        );

        $messages = $this->scope->messages($this->scope->from, $this->scope->to)
            ->groupBy(DB::raw($this->scope->date('messages.created_at')))
            ->select(
                DB::raw($this->scope->date('messages.created_at') . ' as day'),
                DB::raw("SUM(CASE WHEN messages.sender_type = '" . SenderType::Incoming->value . "' THEN 1 ELSE 0 END) as incoming"),
                DB::raw("SUM(CASE WHEN messages.sender_type = '" . SenderType::Outgoing->value . "' THEN 1 ELSE 0 END) as outgoing"),
            )
            ->get()
            ->keyBy('day');

        $previous = $this->countByDay(
            $this->scope->conversations($this->scope->previousFrom, $this->scope->previousTo),
            'conversations.created_at'
        );
        $previousDays = array_values($previous->keys()->sort()->all());

        return collect($days)->values()->map(fn ($day, $index) => [
            'day' => $day,
            'conversations' => (int) ($conversations[$day] ?? 0),
            'messages_in' => (int) ($messages[$day]->incoming ?? 0),
            'messages_out' => (int) ($messages[$day]->outgoing ?? 0),
            // Same slot of the previous period — index-aligned, because the
            // dates differ and only the position is comparable.
            'previous_conversations' => isset($previousDays[$index])
                ? (int) $previous[$previousDays[$index]]
                : 0,
        ])->all();
    }

    private function countByDay($query, string $column)
    {
        return $query
            ->groupBy(DB::raw($this->scope->date($column)))
            ->select(
                DB::raw($this->scope->date($column) . ' as day'),
                DB::raw('COUNT(*) as total')
            )
            ->pluck('total', 'day');
    }

    /**
     * Incoming messages per weekday × hour. Returns a dense 7 × 24 grid: gaps
     * are meaningful here (a silent Sunday morning is a finding), so they are
     * sent as zeros rather than left for the client to infer.
     */
    private function heatmap(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->where('messages.sender_type', SenderType::Incoming->value)
            ->groupBy(
                DB::raw($this->scope->dayOfWeek('messages.created_at')),
                DB::raw($this->scope->hour('messages.created_at'))
            )
            ->select(
                DB::raw($this->scope->dayOfWeek('messages.created_at') . ' as dow'),
                DB::raw($this->scope->hour('messages.created_at') . ' as hour'),
                DB::raw('COUNT(*) as total')
            )
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            $grid[(int) $row->dow][(int) $row->hour] = (int) $row->total;
        }

        $cells = [];
        for ($dow = 0; $dow < 7; $dow++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $cells[] = [
                    'dow' => $dow,
                    'hour' => $hour,
                    'total' => $grid[$dow][$hour] ?? 0,
                ];
            }
        }

        return $cells;
    }

    private function byChannel(): array
    {
        $rows = $this->scope->conversations($this->scope->from, $this->scope->to)
            ->groupBy('connections.channel')
            ->select('connections.channel', DB::raw('COUNT(*) as total'))
            ->get();

        $total = (int) $rows->sum('total');

        return $rows->sortByDesc('total')->map(fn ($row) => [
            'channel' => $row->channel,
            'total' => (int) $row->total,
            'percentage' => $total > 0 ? round(((int) $row->total / $total) * 100, 1) : 0.0,
        ])->values()->all();
    }

    /**
     * The same split one level down. A tenant running three WhatsApp numbers
     * learns nothing from "WhatsApp: 900" — the number that matters is which
     * of the three carries it.
     */
    private function byConnection(): array
    {
        $rows = $this->scope->conversations($this->scope->from, $this->scope->to)
            ->groupBy('connections.id', 'connections.name', 'connections.channel', 'connections.color')
            ->select(
                'connections.id',
                'connections.name',
                'connections.channel',
                'connections.color',
                DB::raw('COUNT(*) as total')
            )
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $total = (int) $rows->sum('total');

        return $rows->map(fn ($row) => [
            'connection_id' => (int) $row->id,
            'name' => $row->name,
            'channel' => $row->channel,
            'color' => $row->color,
            'total' => (int) $row->total,
            'percentage' => $total > 0 ? round(((int) $row->total / $total) * 100, 1) : 0.0,
        ])->all();
    }

    private function messageTypes(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->groupBy('messages.message_type', 'messages.sender_type')
            ->select(
                'messages.message_type',
                'messages.sender_type',
                DB::raw('COUNT(*) as total')
            )
            ->get();

        $byType = [];
        foreach ($rows as $row) {
            $type = $row->message_type;
            $byType[$type] ??= ['type' => $type, 'incoming' => 0, 'outgoing' => 0, 'total' => 0];
            $key = $row->sender_type === SenderType::Incoming->value ? 'incoming' : 'outgoing';
            $byType[$type][$key] += (int) $row->total;
            $byType[$type]['total'] += (int) $row->total;
        }

        usort($byType, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_values($byType);
    }
}
