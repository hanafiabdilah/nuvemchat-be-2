<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    public function tenant(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
                'channel_distribution' => $this->channelDistribution($tenantId, $from, $to),
                'peak_hours' => $this->peakHours($tenantId, $from, $to),
                'load_per_agent' => $this->loadPerAgent($tenantId),
                'missed_chats' => $this->missedChats($tenantId, $from, $to),
            ],
        ]);
    }

    public function agents(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;
        [$from, $to] = $this->resolveRange($request);

        $agents = DB::table('users')
            ->where('users.tenant_id', $tenantId)
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get();

        $handled = $this->totalHandledPerAgent($tenantId, $from, $to)->keyBy('agent_id');
        $resolved = $this->resolvedPerAgent($tenantId, $from, $to)->keyBy('agent_id');
        $firstResponse = $this->firstResponsePerAgent($tenantId, $from, $to)->keyBy('agent_id');
        $avgResponse = $this->avgResponsePerAgent($tenantId, $from, $to)->keyBy('agent_id');

        $rows = $agents->map(function ($agent) use ($handled, $resolved, $firstResponse, $avgResponse) {
            $total = (int) ($handled[$agent->id]->total ?? 0);
            $resolvedCount = (int) ($resolved[$agent->id]->total ?? 0);

            return [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'total_handled' => $total,
                'resolved_count' => $resolvedCount,
                'resolution_rate' => $total > 0 ? round(($resolvedCount / $total) * 100, 2) : 0.0,
                'first_response_seconds' => isset($firstResponse[$agent->id])
                    ? (int) round((float) $firstResponse[$agent->id]->avg_seconds)
                    : null,
                'average_response_seconds' => isset($avgResponse[$agent->id])
                    ? (int) round((float) $avgResponse[$agent->id]->avg_seconds)
                    : null,
            ];
        });

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toIso8601String(),
                    'to' => $to->toIso8601String(),
                ],
                'agents' => $rows->values(),
            ],
        ]);
    }

    private function channelDistribution(int $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $tenantId)
            ->whereBetween('conversations.created_at', [$from, $to])
            ->groupBy('connections.channel')
            ->select('connections.channel', DB::raw('COUNT(conversations.id) as total'))
            ->get();

        $total = (int) $rows->sum('total');

        return $rows->map(function ($row) use ($total) {
            return [
                'channel' => $row->channel,
                'total' => (int) $row->total,
                'percentage' => $total > 0 ? round(((int) $row->total / $total) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    private function peakHours(int $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $tenantId)
            ->where('messages.sender_type', SenderType::Incoming->value)
            ->whereBetween('messages.created_at', [$from, $to])
            ->groupBy(DB::raw('HOUR(messages.created_at)'))
            ->select(
                DB::raw('HOUR(messages.created_at) as hour'),
                DB::raw('COUNT(messages.id) as total')
            )
            ->get()
            ->keyBy('hour');

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = [
                'hour' => $h,
                'total' => isset($rows[$h]) ? (int) $rows[$h]->total : 0,
            ];
        }

        return $hours;
    }

    private function loadPerAgent(int $tenantId): array
    {
        $rows = DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->join('users', 'users.id', '=', 'conversations.user_id')
            ->where('connections.tenant_id', $tenantId)
            ->where('conversations.status', Status::Active->value)
            ->groupBy('users.id', 'users.name')
            ->select(
                'users.id as agent_id',
                'users.name',
                DB::raw('COUNT(conversations.id) as active_conversations')
            )
            ->orderByDesc('active_conversations')
            ->get();

        return $rows->map(fn ($r) => [
            'agent_id' => (int) $r->agent_id,
            'name' => $r->name,
            'active_conversations' => (int) $r->active_conversations,
        ])->all();
    }

    private function missedChats(int $tenantId, Carbon $from, Carbon $to): array
    {
        $missed = DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $tenantId)
            ->whereBetween('conversations.created_at', [$from, $to])
            ->whereNull('conversations.user_id')
            ->count();

        $total = DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $tenantId)
            ->whereBetween('conversations.created_at', [$from, $to])
            ->count();

        return [
            'total_missed' => $missed,
            'total_conversations' => $total,
            'missed_rate' => $total > 0 ? round(($missed / $total) * 100, 2) : 0.0,
        ];
    }

    private function totalHandledPerAgent(int $tenantId, Carbon $from, Carbon $to)
    {
        return DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $tenantId)
            ->whereNotNull('conversations.user_id')
            ->whereBetween('conversations.created_at', [$from, $to])
            ->groupBy('conversations.user_id')
            ->select(
                'conversations.user_id as agent_id',
                DB::raw('COUNT(conversations.id) as total')
            )
            ->get();
    }

    private function resolvedPerAgent(int $tenantId, Carbon $from, Carbon $to)
    {
        return DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $tenantId)
            ->whereNotNull('conversations.user_id')
            ->where('conversations.status', Status::Resolved->value)
            ->whereBetween('conversations.created_at', [$from, $to])
            ->groupBy('conversations.user_id')
            ->select(
                'conversations.user_id as agent_id',
                DB::raw('COUNT(conversations.id) as total')
            )
            ->get();
    }

    private function firstResponsePerAgent(int $tenantId, Carbon $from, Carbon $to)
    {
        return DB::table('conversations as c')
            ->join('connections as conn', 'conn.id', '=', 'c.connection_id')
            ->joinSub(
                DB::table('messages')
                    ->select(
                        'conversation_id',
                        DB::raw('MIN(created_at) as first_at'),
                        DB::raw('MIN(sent_by_user_id) as agent_id')
                    )
                    ->where('sender_type', SenderType::Outgoing->value)
                    ->whereNotNull('sent_by_user_id')
                    ->groupBy('conversation_id'),
                'first_out',
                'first_out.conversation_id',
                '=',
                'c.id'
            )
            ->where('conn.tenant_id', $tenantId)
            ->whereBetween('c.created_at', [$from, $to])
            ->whereNotNull('first_out.agent_id')
            ->groupBy('first_out.agent_id')
            ->select(
                'first_out.agent_id',
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, c.created_at, first_out.first_at)) as avg_seconds')
            )
            ->get();
    }

    private function avgResponsePerAgent(int $tenantId, Carbon $from, Carbon $to)
    {
        $sub = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('connections as conn', 'conn.id', '=', 'c.connection_id')
            ->where('conn.tenant_id', $tenantId)
            ->where('m.sender_type', SenderType::Outgoing->value)
            ->whereNotNull('m.sent_by_user_id')
            ->whereBetween('m.created_at', [$from, $to])
            ->select(
                'm.sent_by_user_id as agent_id',
                DB::raw('TIMESTAMPDIFF(SECOND, (
                    SELECT MAX(prev.created_at)
                    FROM messages prev
                    WHERE prev.conversation_id = m.conversation_id
                      AND prev.sender_type = "' . SenderType::Incoming->value . '"
                      AND prev.created_at < m.created_at
                ), m.created_at) as response_seconds')
            );

        return DB::query()
            ->fromSub($sub, 'pairs')
            ->whereNotNull('response_seconds')
            ->groupBy('agent_id')
            ->select('agent_id', DB::raw('AVG(response_seconds) as avg_seconds'))
            ->get();
    }

    private function resolveRange(Request $request): array
    {
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
