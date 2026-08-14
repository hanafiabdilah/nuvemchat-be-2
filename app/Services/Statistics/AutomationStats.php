<?php

namespace App\Services\Statistics;

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Message\SenderType;
use Illuminate\Support\Facades\DB;

/**
 * What the machines did: flows, the AI hub, and campaigns.
 *
 * All three already record everything needed — flow_states knows which node a
 * run stopped on, ai_hub_runs carries tokens, latency, cost and handoff flags,
 * broadcast_recipients knows exactly who was reached — and none of it has ever
 * been surfaced. The most useful of them is the flow drop-off: the node where
 * automations most often stop is where the flow is broken.
 */
class AutomationStats
{
    public function __construct(private readonly StatsScope $scope) {}

    public function build(): array
    {
        return [
            'flow' => $this->flow(),
            'ai' => $this->ai(),
            'broadcasts' => $this->broadcasts(),
        ];
    }

    private function flow(): array
    {
        $states = DB::table('flow_states')
            ->joinSub($this->scopedConversationIds(), 'c', 'c.id', '=', 'flow_states.conversation_id')
            ->selectRaw(implode(', ', [
                'COUNT(*) as entered',
                "SUM(CASE WHEN flow_states.status = '" . FlowStateStatus::Completed->value . "' THEN 1 ELSE 0 END) as completed",
                "SUM(CASE WHEN flow_states.status = '" . FlowStateStatus::Running->value . "' THEN 1 ELSE 0 END) as running",
                "SUM(CASE WHEN flow_states.status = '" . FlowStateStatus::Stopped->value . "' THEN 1 ELSE 0 END) as stopped",
                "SUM(CASE WHEN flow_states.status = '" . FlowStateStatus::Failed->value . "' THEN 1 ELSE 0 END) as failed",
            ]))
            ->first();

        $entered = (int) ($states->entered ?? 0);
        $completed = (int) ($states->completed ?? 0);

        // Where runs that did not finish are sitting. `flows.name` and the
        // node's own label make this readable without opening the builder.
        $dropOff = DB::table('flow_states')
            ->joinSub($this->scopedConversationIds(), 'c', 'c.id', '=', 'flow_states.conversation_id')
            ->join('flow_nodes', 'flow_nodes.id', '=', 'flow_states.current_node_id')
            ->leftJoin('flows', 'flows.id', '=', 'flow_states.flow_id')
            ->where('flow_states.status', '!=', FlowStateStatus::Completed->value)
            ->groupBy('flow_nodes.id', 'flow_nodes.type', 'flow_nodes.data', 'flows.name')
            ->select(
                'flow_nodes.id as node_id',
                'flow_nodes.type as node_type',
                'flow_nodes.data as node_data',
                'flows.name as flow_name',
                DB::raw('COUNT(*) as total')
            )
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        return [
            'entered' => $entered,
            'completed' => $completed,
            'running' => (int) ($states->running ?? 0),
            'stopped' => (int) ($states->stopped ?? 0),
            'failed' => (int) ($states->failed ?? 0),
            'completion_rate' => $entered > 0 ? round(($completed / $entered) * 100, 1) : 0.0,
            'drop_off' => $dropOff->map(fn ($row) => [
                'node_id' => (int) $row->node_id,
                'node_type' => $row->node_type,
                'label' => $this->nodeLabel($row->node_data),
                'flow_name' => $row->flow_name,
                'total' => (int) $row->total,
            ])->all(),
        ];
    }

    private function scopedConversationIds()
    {
        return $this->scope->conversations($this->scope->from, $this->scope->to)
            ->select('conversations.id');
    }

    /** The builder stores the node's display name inside its JSON `data`. */
    private function nodeLabel(?string $json): ?string
    {
        $data = $json ? json_decode($json, true) : null;

        if (! is_array($data)) {
            return null;
        }

        foreach (['label', 'name', 'title'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return null;
    }

    private function ai(): array
    {
        // Qualified: the per-agent breakdown joins ai_hub_agents, which has its
        // own tenant_id and created_at.
        $runs = DB::table('ai_hub_runs')
            ->where('ai_hub_runs.tenant_id', $this->scope->tenantId)
            ->whereBetween('ai_hub_runs.created_at', [$this->scope->from, $this->scope->to]);

        $totals = (clone $runs)
            ->selectRaw(implode(', ', [
                'COUNT(*) as total',
                // The hub's own status string is stored verbatim and its
                // casing is the hub's business, so both ends are normalised.
                "SUM(CASE WHEN UPPER(ai_hub_runs.status) IN ('COMPLETED', 'SUCCEEDED', 'SUCCESS') THEN 1 ELSE 0 END) as succeeded",
                "SUM(CASE WHEN UPPER(ai_hub_runs.status) IN ('FAILED', 'ERROR') THEN 1 ELSE 0 END) as failed",
                'SUM(CASE WHEN ai_hub_runs.handoff_triggered = 1 THEN 1 ELSE 0 END) as handoffs',
                'SUM(ai_hub_runs.total_tokens) as tokens',
                'SUM(ai_hub_runs.cost_usd) as cost_usd',
                'AVG(ai_hub_runs.latency_ms) as avg_latency_ms',
            ]))
            ->first();

        $total = (int) ($totals->total ?? 0);

        $byAgent = (clone $runs)
            ->leftJoin('ai_hub_agents', 'ai_hub_agents.id', '=', 'ai_hub_runs.ai_hub_agent_id')
            ->groupBy('ai_hub_agents.id', 'ai_hub_agents.name')
            ->select(
                'ai_hub_agents.id as agent_id',
                'ai_hub_agents.name',
                DB::raw('COUNT(*) as runs'),
                DB::raw('SUM(ai_hub_runs.total_tokens) as tokens'),
                DB::raw('SUM(ai_hub_runs.cost_usd) as cost_usd'),
                DB::raw('SUM(CASE WHEN ai_hub_runs.handoff_triggered = 1 THEN 1 ELSE 0 END) as handoffs'),
                DB::raw('AVG(ai_hub_runs.latency_ms) as avg_latency_ms')
            )
            ->orderByDesc('runs')
            ->limit(10)
            ->get();

        return [
            'runs' => $total,
            'succeeded' => (int) ($totals->succeeded ?? 0),
            'failed' => (int) ($totals->failed ?? 0),
            'handoffs' => (int) ($totals->handoffs ?? 0),
            'handoff_rate' => $total > 0 ? round(((int) $totals->handoffs / $total) * 100, 1) : 0.0,
            'tokens' => (int) ($totals->tokens ?? 0),
            'cost_usd' => round((float) ($totals->cost_usd ?? 0), 4),
            'avg_latency_ms' => $totals->avg_latency_ms !== null ? (int) round((float) $totals->avg_latency_ms) : null,
            'agents' => $byAgent->map(fn ($row) => [
                'agent_id' => $row->agent_id ? (int) $row->agent_id : null,
                'name' => $row->name,
                'runs' => (int) $row->runs,
                'tokens' => (int) $row->tokens,
                'cost_usd' => round((float) $row->cost_usd, 4),
                'handoffs' => (int) $row->handoffs,
                'avg_latency_ms' => $row->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
            ])->all(),
        ];
    }

    private function broadcasts(): array
    {
        // Qualified for the same reason as the AI runs: `recent` joins
        // connections, which carries its own tenant_id and created_at.
        $campaigns = DB::table('broadcasts')
            ->where('broadcasts.tenant_id', $this->scope->tenantId)
            ->whereBetween('broadcasts.created_at', [$this->scope->from, $this->scope->to]);

        $totals = (clone $campaigns)
            ->selectRaw(implode(', ', [
                'COUNT(*) as campaigns',
                'SUM(broadcasts.total_recipients) as recipients',
                'SUM(broadcasts.sent_count) as sent',
                'SUM(broadcasts.failed_count) as failed',
                'SUM(broadcasts.skipped_count) as skipped',
            ]))
            ->first();

        $recent = (clone $campaigns)
            ->leftJoin('connections', 'connections.id', '=', 'broadcasts.connection_id')
            ->orderByDesc('broadcasts.created_at')
            ->limit(8)
            ->get([
                'broadcasts.id',
                'broadcasts.name',
                'broadcasts.status',
                'broadcasts.total_recipients',
                'broadcasts.sent_count',
                'broadcasts.failed_count',
                'broadcasts.skipped_count',
                'broadcasts.created_at',
                'connections.channel',
                'connections.name as connection_name',
            ]);

        // Did the blast start a conversation? Counted from the recipients'
        // threads, not from the campaign row, because only the thread knows
        // whether the customer wrote back.
        $replied = DB::table('broadcast_recipients')
            ->join('broadcasts', 'broadcasts.id', '=', 'broadcast_recipients.broadcast_id')
            ->where('broadcasts.tenant_id', $this->scope->tenantId)
            ->whereBetween('broadcasts.created_at', [$this->scope->from, $this->scope->to])
            ->where('broadcast_recipients.status', RecipientStatus::Sent->value)
            ->whereNotNull('broadcast_recipients.conversation_id')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'broadcast_recipients.conversation_id')
                    ->where('messages.sender_type', SenderType::Incoming->value)
                    ->whereColumn('messages.created_at', '>', 'broadcast_recipients.sent_at');
            })
            ->count();

        $sent = (int) ($totals->sent ?? 0);

        $optOuts = DB::table('contacts')
            ->where('tenant_id', $this->scope->tenantId)
            ->whereBetween('broadcast_opted_out_at', [$this->scope->from, $this->scope->to])
            ->count();

        return [
            'campaigns' => (int) ($totals->campaigns ?? 0),
            'recipients' => (int) ($totals->recipients ?? 0),
            'sent' => $sent,
            'failed' => (int) ($totals->failed ?? 0),
            'skipped' => (int) ($totals->skipped ?? 0),
            'replied' => $replied,
            'reply_rate' => $sent > 0 ? round(($replied / $sent) * 100, 1) : 0.0,
            'opt_outs' => $optOuts,
            'recent' => $recent->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'status' => $row->status,
                'channel' => $row->channel,
                'connection_name' => $row->connection_name,
                'recipients' => (int) $row->total_recipients,
                'sent' => (int) $row->sent_count,
                'failed' => (int) $row->failed_count,
                'skipped' => (int) $row->skipped_count,
                'created_at' => $row->created_at,
            ])->all(),
        ];
    }
}
