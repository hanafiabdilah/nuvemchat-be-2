<?php

namespace App\Services\Statistics;

use App\Enums\Conversation\Status;
use App\Enums\Message\SenderType;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
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
    /**
     * How recently a thread must have moved to still count as queue.
     *
     * Without a bound this block reported every conversation nobody ever
     * accepted, back to the workspace's first day — one production tenant sat
     * at 3,858 "waiting", a number no shift could act on and that no date
     * preset could move. Nothing drains the Pending status on its own: only
     * Accept, Resolve, or the AI picking a thread up, and the automatic
     * window-expiry closer skips Pending on purpose. So the backlog is
     * permanent, and a live strip that leads with it is telling the shift
     * about work from two years ago.
     *
     * A week is the span in which an answer is still an answer. Older threads
     * are not lost — they stay in the inbox, and the period metrics above
     * still count them.
     */
    private const QUEUE_ACTIVE_DAYS = 7;

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
     *
     * "Right now" means what a shift could pick up today: open, visible in the
     * inbox, and moved within QUEUE_ACTIVE_DAYS. It still ignores the date
     * range above it, and the client says so.
     */
    private function snapshot(): array
    {
        $byStatus = $this->liveQueue()
            ->groupBy('conversations.status')
            ->select('conversations.status', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'status');

        $needsHuman = $this->liveQueue()
            ->where('conversations.needs_human', true)
            ->count();

        // Waiting on us: still in the queue, and the last thing said was the
        // customer's, more than an hour ago.
        $waiting = $this->liveQueue()
            ->where('conversations.last_message_at', '<', now()->subHour())
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages as last_m')
                    ->whereColumn('last_m.conversation_id', 'conversations.id')
                    ->where('last_m.sender_type', SenderType::Incoming->value)
                    ->whereRaw('last_m.id = (select max(inner_m.id) from messages inner_m where inner_m.conversation_id = conversations.id)');
            })
            ->count();

        // Not through liveQueue(): closing a thread is the one thing here that
        // already carries its own window, and a conversation resolved this
        // morning after a month of silence is still resolved this morning.
        $resolvedToday = $this->inboxConversations()
            ->where('conversations.resolved_at', '>=', now($this->scope->timezone)->startOfDay()->utc())
            ->count();

        return [
            'pending' => (int) ($byStatus[Status::Pending->value] ?? 0),
            'active' => (int) ($byStatus[Status::Active->value] ?? 0),
            'ai_handling' => (int) ($byStatus[Status::AiHandling->value] ?? 0),
            'needs_human' => $needsHuman,
            'waiting_over_1h' => $waiting,
            'resolved_today' => $resolvedToday,
            // So the client can say which window it is showing instead of
            // leaving "Right now" to mean whatever the reader assumes.
            'queue_active_days' => self::QUEUE_ACTIVE_DAYS,
        ];
    }

    /**
     * Conversations as the inbox itself counts them.
     *
     * The two exclusions are copied from ConversationController@index, and
     * both matter here. A conversation with no message at all is not waiting
     * for anybody — the Live Chat Widget opens one the moment a visitor loads
     * the page, before they type a word, so every bounce was landing in
     * "waiting in queue" while being invisible in every agent's list. And a
     * removed group is gone from the panel by definition.
     */
    private function inboxConversations(): Builder
    {
        return $this->scope->conversations()
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id');
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('contacts')
                    ->whereColumn('contacts.id', 'conversations.contact_id')
                    ->whereNotNull('contacts.group_removed_at');
            });
    }

    /**
     * What the shift could actually pick up: open, in the inbox, and moved
     * within QUEUE_ACTIVE_DAYS.
     *
     * Recency is read from `last_message_at` rather than `created_at` — the
     * exception to this file's rule, and the right one here. That column is
     * what the inbox sorts by, so the strip and the list agree on which
     * threads are current; and because it follows the message's own clock, an
     * import of two-year-old history lands as two-year-old history instead of
     * as a queue that appeared this morning.
     */
    private function liveQueue(): Builder
    {
        return $this->inboxConversations()
            ->whereIn('conversations.status', [
                Status::Pending->value,
                Status::Active->value,
                Status::AiHandling->value,
            ])
            ->where('conversations.last_message_at', '>=', now()->subDays(self::QUEUE_ACTIVE_DAYS));
    }

    /**
     * People who wrote for the first time: reached through the period's
     * conversations, not counted straight off the contacts table.
     *
     * Counting rows in `contacts` was wrong twice over. It ignored the filter
     * set entirely — the chat and e-mail views both reported the whole
     * tenant's intake, which is what made the two inboxes look merged — and it
     * counted contacts that never opened a conversation at all, so an import
     * read as a week of new customers. Going through the same conversations
     * every other headline is built from also makes this the exact complement
     * of returningContacts(): the people seen this period, split by whether we
     * already knew them.
     */
    private function newContacts(Carbon $from, Carbon $to): int
    {
        return $this->contactsSeen($from, $to)
            ->where('contacts.created_at', '>=', $from)
            ->count('contacts.id');
    }

    /**
     * Someone we already knew who came back this period — the other half of
     * "contacts new", and the one that says whether the base is sticky.
     */
    private function returningContacts(Carbon $from, Carbon $to): int
    {
        return $this->contactsSeen($from, $to)
            ->where('contacts.created_at', '<', $from)
            ->count('contacts.id');
    }

    /**
     * Distinct people behind the period's conversations. Groups are excluded
     * even when the filter bar asks for them: a group is a room, not a new
     * customer, and counting it here would report rooms as people.
     */
    private function contactsSeen(Carbon $from, Carbon $to)
    {
        return $this->scope->conversations($from, $to)
            ->join('contacts', 'contacts.id', '=', 'conversations.contact_id')
            ->where('contacts.is_group', false)
            ->distinct();
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
