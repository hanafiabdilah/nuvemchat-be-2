<?php

namespace App\Services\Statistics;

use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The headline numbers, each one paired with the same number for the previous
 * period of equal length so the page can show a direction rather than a bare
 * figure — "412 conversas" says nothing until you know last week was 300.
 *
 * Nothing here counts a conversation as "missed" for being unassigned. The old
 * page did, and it was wrong twice over: e-mail is a shared inbox that is never
 * assigned to anyone, and a thread the AI answered end to end was never missed
 * at all. What is reported instead is whether the customer *got an answer*.
 */
class OverviewStats
{
    private readonly ConversationTimings $timings;

    public function __construct(private readonly StatsScope $scope)
    {
        $this->timings = new ConversationTimings($scope);
    }

    public function build(): array
    {
        return [
            'current' => $this->metrics($this->scope->from, $this->scope->to),
            'previous' => $this->metrics($this->scope->previousFrom, $this->scope->previousTo),
            'now' => $this->snapshot(),
        ];
    }

    private function metrics(Carbon $from, Carbon $to): array
    {
        $counts = DB::query()
            ->fromSub($this->timings->query($from, $to), 'r')
            ->selectRaw(implode(', ', [
                'COUNT(*) as total',
                'SUM(CASE WHEN first_in_at IS NOT NULL THEN 1 ELSE 0 END) as inbound_total',
                'SUM(CASE WHEN first_in_at IS NOT NULL AND has_outgoing = 1 THEN 1 ELSE 0 END) as answered_any',
                'SUM(CASE WHEN first_in_at IS NOT NULL AND first_human_at IS NOT NULL THEN 1 ELSE 0 END) as answered_human',
                // Automation means the flow or the AI answered, not merely
                // "no agent did" — a message pushed through the public API has
                // a human behind it somewhere and should not inflate this.
                'SUM(CASE WHEN first_in_at IS NOT NULL AND first_human_at IS NULL AND has_bot = 1 THEN 1 ELSE 0 END) as bot_only',
                'SUM(CASE WHEN first_in_at IS NOT NULL AND has_outgoing = 0 THEN 1 ELSE 0 END) as unanswered',
                "SUM(CASE WHEN status = '" . Status::Resolved->value . "' THEN 1 ELSE 0 END) as resolved",
                'SUM(CASE WHEN resolved_at IS NOT NULL AND resolution_seconds >= 0 THEN 1 ELSE 0 END) as timed_resolutions',
            ]))
            ->first();

        $total = (int) ($counts->total ?? 0);
        $inbound = (int) ($counts->inbound_total ?? 0);
        $answeredHuman = (int) ($counts->answered_human ?? 0);
        $answeredAny = (int) ($counts->answered_any ?? 0);
        $botOnly = (int) ($counts->bot_only ?? 0);
        $resolved = (int) ($counts->resolved ?? 0);
        $timedResolutions = (int) ($counts->timed_resolutions ?? 0);

        // Population for the response-time percentiles: customer wrote first,
        // a human answered. Counted separately because it excludes threads the
        // bot handled alone, which have no human response time to speak of.
        $answeredQuery = $this->timings->answered($from, $to);
        $answeredCount = (clone $answeredQuery)->count();

        $resolutionQuery = DB::query()
            ->fromSub($this->timings->query($from, $to), 'r')
            ->whereNotNull('r.resolved_at')
            ->where('r.resolution_seconds', '>=', 0);

        $messages = $this->scope->messages($from, $to)
            ->selectRaw(implode(', ', [
                "SUM(CASE WHEN messages.sender_type = '" . SenderType::Incoming->value . "' THEN 1 ELSE 0 END) as incoming",
                "SUM(CASE WHEN messages.sender_type = '" . SenderType::Outgoing->value . "' THEN 1 ELSE 0 END) as outgoing",
            ]))
            ->first();

        return [
            'conversations' => $total,
            'conversations_inbound' => $inbound,
            'messages_in' => (int) ($messages->incoming ?? 0),
            'messages_out' => (int) ($messages->outgoing ?? 0),
            'contacts_new' => $this->newContacts($from, $to),
            'contacts_returning' => $this->returningContacts($from, $to),
            'answered' => $answeredAny,
            'unanswered' => (int) ($counts->unanswered ?? 0),
            'response_rate' => $this->rate($answeredAny, $inbound),
            'human_response_rate' => $this->rate($answeredHuman, $inbound),
            'first_response_median_seconds' => Percentiles::median($answeredQuery, 'r.response_seconds', $answeredCount),
            'first_response_p90_seconds' => Percentiles::at($answeredQuery, 'r.response_seconds', $answeredCount, 90),
            'resolved' => $resolved,
            'resolution_rate' => $this->rate($resolved, $total),
            'resolution_median_seconds' => Percentiles::median($resolutionQuery, 'r.resolution_seconds', $timedResolutions),
            // Null (not zero) while no closure has been timed yet: the column
            // that records it only started filling on deploy, and an eager zero
            // would read as "instant" instead of "not measured".
            'resolution_sample' => $timedResolutions,
            'automation_rate' => $this->rate($botOnly, $inbound),
            'automated_conversations' => $botOnly,
        ];
    }

    /**
     * State right now, not over the period. Kept in its own block — and
     * labelled as such by the client — because mixing a live queue depth into
     * a row of period totals is exactly what made the old page confusing.
     */
    private function snapshot(): array
    {
        $byStatus = $this->scope->conversations()
            ->groupBy('conversations.status')
            ->select('conversations.status', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'status');

        $needsHuman = $this->scope->conversations()
            ->where('conversations.needs_human', true)
            ->count();

        // Waiting on us: still open, and the last thing said was the
        // customer's, more than an hour ago.
        $waiting = $this->scope->conversations()
            ->whereIn('conversations.status', [Status::Pending->value, Status::Active->value, Status::AiHandling->value])
            ->where('conversations.last_message_at', '<', now()->subHour())
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages as last_m')
                    ->whereColumn('last_m.conversation_id', 'conversations.id')
                    ->where('last_m.sender_type', SenderType::Incoming->value)
                    ->whereRaw('last_m.id = (select max(inner_m.id) from messages inner_m where inner_m.conversation_id = conversations.id)');
            })
            ->count();

        $resolvedToday = $this->scope->conversations()
            ->where('conversations.resolved_at', '>=', now($this->scope->timezone)->startOfDay()->utc())
            ->count();

        return [
            'pending' => (int) ($byStatus[Status::Pending->value] ?? 0),
            'active' => (int) ($byStatus[Status::Active->value] ?? 0),
            'ai_handling' => (int) ($byStatus[Status::AiHandling->value] ?? 0),
            'needs_human' => $needsHuman,
            'waiting_over_1h' => $waiting,
            'resolved_today' => $resolvedToday,
        ];
    }

    private function newContacts(Carbon $from, Carbon $to): int
    {
        return DB::table('contacts')
            ->where('tenant_id', $this->scope->tenantId)
            ->where('is_group', false)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /**
     * Someone we already knew who came back this period — the other half of
     * "contacts new", and the one that says whether the base is sticky.
     */
    private function returningContacts(Carbon $from, Carbon $to): int
    {
        return $this->scope->conversations($from, $to)
            ->join('contacts', 'contacts.id', '=', 'conversations.contact_id')
            ->where('contacts.created_at', '<', $from)
            ->distinct()
            ->count('contacts.id');
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
