<?php

namespace App\Services\Lead;

use App\Enums\Lead\Temperature;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * How warm a lead is, from signals Pingly already stores.
 *
 * No model, no external call, and no agent input — the whole value of this axis
 * is that it keeps working when nobody maintains it. An agent who forgets to
 * drag a card still sees it go cold, which is the moment they most need to be
 * told about it.
 *
 * Every duration is measured from `created_at`, never `sent_at`: sent_at can be
 * backdated by a history import, and a lead full of imported year-old messages
 * would otherwise read as blazing hot. Same rule the statistics module follows.
 */
class TemperatureScorer
{
    /** [hours since the customer last wrote, points] — first match wins. */
    private const RECENCY_BANDS = [
        [24, 50],
        [72, 35],
        [168, 20],
        [336, 8],
    ];

    private const ENGAGEMENT_WINDOW_DAYS = 14;

    private const STAGE_MOVE_WINDOW_DAYS = 7;

    /**
     * Recompute and persist.
     *
     * Returns true when the *band* changed, which is the only thing worth
     * telling the dashboard about — the raw score drifts every hour for every
     * lead, and broadcasting that would set every agent's board flickering all
     * day with nothing visible actually changing.
     */
    public function apply(Lead $lead): bool
    {
        $before = $lead->temperature;
        $signals = $this->gather($lead);
        $score = $this->scoreFrom($signals, $lead);

        $lead->forceFill([
            'temperature_score' => $score,
            'temperature' => Temperature::fromScore($score),
            'last_inbound_at' => $signals['last_inbound_at'],
        ])->save();

        return $lead->temperature !== $before;
    }

    public function score(Lead $lead): int
    {
        return $this->scoreFrom($this->gather($lead), $lead);
    }

    /**
     * Everything the score needs, in three queries rather than one per rule.
     *
     * Scoped to every thread this person has had, not just the one that opened
     * the lead: a conversation dies at Resolved and the next message starts a
     * new row, so reading a single thread would look like silence the moment an
     * agent tidies up.
     *
     * @return array{last_inbound_at: ?Carbon, recent_inbound: int, customer_spoke_last: bool}
     */
    private function gather(Lead $lead): array
    {
        $conversationIds = Conversation::where('contact_id', $lead->contact_id)->pluck('id')->all();

        if ($conversationIds === []) {
            return ['last_inbound_at' => null, 'recent_inbound' => 0, 'customer_spoke_last' => false];
        }

        $lastInbound = Message::whereIn('conversation_id', $conversationIds)
            ->where('sender_type', SenderType::Incoming)
            ->max('created_at');

        $recentInbound = Message::whereIn('conversation_id', $conversationIds)
            ->where('sender_type', SenderType::Incoming)
            ->where('created_at', '>=', now()->subDays(self::ENGAGEMENT_WINDOW_DAYS))
            ->count();

        $latest = Message::whereIn('conversation_id', $conversationIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['sender_type']);

        return [
            'last_inbound_at' => $lastInbound ? Carbon::parse($lastInbound) : null,
            'recent_inbound' => $recentInbound,
            'customer_spoke_last' => $latest?->sender_type === SenderType::Incoming,
        ];
    }

    private function scoreFrom(array $signals, Lead $lead): int
    {
        return min(100,
            $this->recencyPoints($signals['last_inbound_at'])
            + $this->volumePoints($signals['recent_inbound'])
            // Did they answer us, or are we talking to a wall? Read as "who
            // spoke last", which says the same thing for a fraction of the work:
            // if our outbound is the newest message, the ball is in their court
            // and they have not moved it.
            + ($signals['customer_spoke_last'] ? 15 : 0)
            + $this->momentumPoints($lead)
        );
    }

    /** Recency dominates: nothing predicts a reply like a recent one. */
    private function recencyPoints(?Carbon $lastInboundAt): int
    {
        if (! $lastInboundAt) {
            return 0;
        }

        $hours = $lastInboundAt->diffInHours(now());

        foreach (self::RECENCY_BANDS as [$limit, $points]) {
            if ($hours < $limit) {
                return $points;
            }
        }

        return 0;
    }

    /** Someone writing repeatedly is more interested than someone who wrote once. */
    private function volumePoints(int $count): int
    {
        return match (true) {
            $count >= 5 => 25,
            $count >= 2 => 15,
            $count === 1 => 5,
            default => 0,
        };
    }

    /** A card that moved recently is a live deal, whoever moved it. */
    private function momentumPoints(Lead $lead): int
    {
        return $lead->stage_changed_at
            && $lead->stage_changed_at->gt(now()->subDays(self::STAGE_MOVE_WINDOW_DAYS))
            ? 10
            : 0;
    }
}
