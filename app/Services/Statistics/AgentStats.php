<?php

namespace App\Services\Statistics;

use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use Illuminate\Support\Facades\DB;

/**
 * Per-agent numbers, and the team average to read them against — a lone "14
 * min" means nothing until you know the team sits at 6.
 *
 * The old table reported an "average response" built from every outbound
 * message paired with whatever inbound preceded it, uncapped, so one reply
 * picked up the next morning counted as a twelve-hour response and swamped the
 * agent's mean. It also credited first responses to MIN(sent_by_user_id) — the
 * lowest user id in the thread, not the person who actually replied first.
 *
 * What is reported instead: how much each agent carried, how much of it they
 * closed, and how fast they answered the conversations they answered *first* —
 * as a median, so one long night does not rewrite a month.
 */
class AgentStats
{
    /**
     * Ceiling on the number of individual response times pulled into PHP for
     * the per-agent medians. Beyond this the medians describe the most recent
     * conversations in the period rather than all of them; every count on the
     * page is aggregated in SQL and stays exact regardless.
     */
    private const MEDIAN_SAMPLE_CAP = 50000;

    private readonly ConversationTimings $timings;

    public function __construct(private readonly StatsScope $scope)
    {
        $this->timings = new ConversationTimings($scope);
    }

    public function build(): array
    {
        $agents = DB::table('users')
            ->where('users.tenant_id', $this->scope->tenantId)
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get();

        $assigned = $this->assignedPerAgent();
        $resolved = $this->resolvedPerAgent();
        $activity = $this->activityPerAgent();
        $workload = $this->workloadPerAgent();
        $responses = $this->responseSecondsPerAgent();
        $hourly = $this->hourlyPerAgent();

        $rows = $agents->map(function ($agent) use ($assigned, $resolved, $activity, $workload, $responses, $hourly) {
            $assignedCount = (int) ($assigned[$agent->id] ?? 0);
            $resolvedCount = (int) ($resolved[$agent->id] ?? 0);
            $seconds = $responses[$agent->id] ?? [];

            return [
                'agent_id' => (int) $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'assigned' => $assignedCount,
                'replied_conversations' => (int) ($activity[$agent->id]->conversations ?? 0),
                'messages_sent' => (int) ($activity[$agent->id]->messages ?? 0),
                'resolved' => $resolvedCount,
                'resolution_rate' => $assignedCount > 0
                    ? round(($resolvedCount / $assignedCount) * 100, 1)
                    : 0.0,
                'first_response_median_seconds' => StatsScope::median($seconds),
                'first_response_p90_seconds' => StatsScope::percentile($seconds, 90),
                'first_responses' => count($seconds),
                'workload_now' => (int) ($workload[$agent->id] ?? 0),
                'hourly' => $this->denseHours($hourly[$agent->id] ?? []),
            ];
        })->values();

        // Agents with no trace in the period are dropped from the board but
        // still counted in the roster, so an inactive account does not drag the
        // team average down to look like a service problem.
        $active = $rows->filter(fn ($row) => $row['assigned'] > 0
            || $row['messages_sent'] > 0
            || $row['workload_now'] > 0)->values();

        return [
            'agents' => $active->all(),
            'roster_total' => $rows->count(),
            'team' => $this->team($active),
        ];
    }

    private function team($rows): array
    {
        $medians = $rows->pluck('first_response_median_seconds')->filter(fn ($value) => $value !== null)->values()->all();
        $resolved = (int) $rows->sum('resolved');
        $assignedTotal = (int) $rows->sum('assigned');

        return [
            'agents' => $rows->count(),
            'assigned_avg' => $rows->count() > 0 ? round($assignedTotal / $rows->count(), 1) : 0.0,
            'messages_avg' => $rows->count() > 0 ? round((int) $rows->sum('messages_sent') / $rows->count(), 1) : 0.0,
            'first_response_median_seconds' => StatsScope::median($medians),
            'resolution_rate' => $assignedTotal > 0 ? round(($resolved / $assignedTotal) * 100, 1) : 0.0,
        ];
    }

    private function assignedPerAgent()
    {
        return $this->scope->conversations($this->scope->from, $this->scope->to)
            ->whereNotNull('conversations.user_id')
            ->groupBy('conversations.user_id')
            ->select('conversations.user_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'user_id');
    }

    private function resolvedPerAgent()
    {
        return $this->scope->conversations($this->scope->from, $this->scope->to)
            ->whereNotNull('conversations.user_id')
            ->where('conversations.status', Status::Resolved->value)
            ->groupBy('conversations.user_id')
            ->select('conversations.user_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'user_id');
    }

    /** Messages typed, and how many distinct threads they landed in. */
    private function activityPerAgent()
    {
        return $this->scope->messages($this->scope->from, $this->scope->to)
            ->whereNotNull('messages.sent_by_user_id')
            ->groupBy('messages.sent_by_user_id')
            ->select(
                'messages.sent_by_user_id as agent_id',
                DB::raw('COUNT(*) as messages'),
                DB::raw('COUNT(DISTINCT messages.conversation_id) as conversations')
            )
            ->get()
            ->keyBy('agent_id');
    }

    /** Open conversations sitting with each agent right now. */
    private function workloadPerAgent()
    {
        return $this->scope->conversations()
            ->whereNotNull('conversations.user_id')
            ->where('conversations.status', Status::Active->value)
            ->groupBy('conversations.user_id')
            ->select('conversations.user_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'user_id');
    }

    /**
     * Response times grouped by whoever answered first.
     *
     * @return array<int, array<int, int>>
     */
    private function responseSecondsPerAgent(): array
    {
        $rows = DB::query()
            ->fromSub($this->timings->answered($this->scope->from, $this->scope->to), 'a')
            ->whereNotNull('a.responder_id')
            ->orderByDesc('a.conversation_id')
            ->limit(self::MEDIAN_SAMPLE_CAP)
            ->get(['a.responder_id', 'a.response_seconds']);

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[(int) $row->responder_id][] = (int) $row->response_seconds;
        }

        return $byAgent;
    }

    /** Outbound messages per agent per hour of the day, in the viewer's clock. */
    private function hourlyPerAgent(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->where('messages.sender_type', SenderType::Outgoing->value)
            ->whereNotNull('messages.sent_by_user_id')
            ->groupBy('messages.sent_by_user_id', DB::raw($this->scope->hour('messages.created_at')))
            ->select(
                'messages.sent_by_user_id as agent_id',
                DB::raw($this->scope->hour('messages.created_at') . ' as hour'),
                DB::raw('COUNT(*) as total')
            )
            ->get();

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[(int) $row->agent_id][(int) $row->hour] = (int) $row->total;
        }

        return $byAgent;
    }

    /** @return array<int, int> 24 values, zero-filled. */
    private function denseHours(array $hours): array
    {
        $dense = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $dense[] = (int) ($hours[$hour] ?? 0);
        }

        return $dense;
    }
}
