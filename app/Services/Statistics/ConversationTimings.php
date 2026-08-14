<?php

namespace App\Services\Statistics;

use App\Enums\Message\SenderType;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per conversation: when the customer first wrote, when a human first
 * wrote back, who that human was, whether a bot spoke, and when it closed.
 *
 * Nearly every service metric on the statistics page is a different reading of
 * this same table — response rate, time to first response, the SLA histogram,
 * the funnel, per-agent speed — so it is built once, in SQL, and each caller
 * aggregates over it.
 *
 * Two deliberate choices:
 *
 * - "First" is picked by `messages.id`, not by comparing timestamps. Ids are
 *   insertion order, which is the same order as `created_at`, and MIN(id)
 *   collapses to one aggregate where MIN(timestamp) would leave us unable to
 *   say *which* agent that first reply belonged to. The ids are then joined
 *   back once, on the primary key.
 * - Everything is measured from `created_at` (when the row landed here), never
 *   `sent_at`, which a history import can back-date by months.
 */
class ConversationTimings
{
    public function __construct(private readonly StatsScope $scope) {}

    /**
     * @return Builder columns: conversation_id, user_id, status, created_at,
     *                resolved_at, first_in_at, first_human_at, responder_id,
     *                has_bot, has_outgoing, response_seconds, resolution_seconds
     */
    public function query(Carbon $from, Carbon $to): Builder
    {
        $incoming = SenderType::Incoming->value;
        $outgoing = SenderType::Outgoing->value;

        $inner = $this->scope->conversations($from, $to)
            ->leftJoin('messages as m', 'm.conversation_id', '=', 'conversations.id')
            ->groupBy(
                'conversations.id',
                'conversations.user_id',
                'conversations.status',
                'conversations.created_at',
                'conversations.resolved_at',
            )
            ->select([
                'conversations.id as conversation_id',
                'conversations.user_id',
                'conversations.status',
                'conversations.created_at',
                'conversations.resolved_at',
                DB::raw("MIN(CASE WHEN m.sender_type = '{$incoming}' THEN m.id END) as first_in_id"),
                DB::raw("MIN(CASE WHEN m.sender_type = '{$outgoing}' AND m.sent_by_user_id IS NOT NULL THEN m.id END) as first_human_id"),
                DB::raw("MAX(CASE WHEN m.sender_type = '{$outgoing}' THEN 1 ELSE 0 END) as has_outgoing"),
                DB::raw("MAX(CASE WHEN m.sender_type = '{$outgoing}' AND (m.sent_by_flow_id IS NOT NULL OR m.sent_by_ai_hub_agent_id IS NOT NULL) THEN 1 ELSE 0 END) as has_bot"),
            ]);

        return DB::query()
            ->fromSub($inner, 't')
            ->leftJoin('messages as mi', 'mi.id', '=', 't.first_in_id')
            ->leftJoin('messages as mh', 'mh.id', '=', 't.first_human_id')
            ->select([
                't.conversation_id',
                't.user_id',
                't.status',
                't.created_at',
                't.resolved_at',
                't.has_bot',
                't.has_outgoing',
                'mi.created_at as first_in_at',
                'mh.created_at as first_human_at',
                'mh.sent_by_user_id as responder_id',
                DB::raw(SqlDialect::diffSeconds('mi.created_at', 'mh.created_at') . ' as response_seconds'),
                DB::raw(SqlDialect::diffSeconds('t.created_at', 't.resolved_at') . ' as resolution_seconds'),
            ]);
    }

    /**
     * Conversations whose first response can be timed: the customer wrote
     * first and a human answered afterwards.
     *
     * The `> 0` guard drops campaign-opened threads, where "our" message comes
     * before the customer's — a negative duration there is not a fast reply,
     * it is a different kind of conversation entirely.
     */
    public function answered(Carbon $from, Carbon $to): Builder
    {
        return DB::query()
            ->fromSub($this->query($from, $to), 'r')
            ->whereNotNull('r.first_in_at')
            ->whereNotNull('r.first_human_at')
            ->where('r.response_seconds', '>', 0);
    }
}
