<?php

namespace App\Services\Statistics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the period was *about*: the tags agents put on conversations, and the
 * contacts who took up the most of it.
 *
 * Tags are the only record of why customers wrote — the platform has collected
 * them since tagging shipped and no report has ever read them back.
 */
class TopicStats
{
    public function __construct(private readonly StatsScope $scope) {}

    public function build(): array
    {
        return [
            'tags' => $this->tags(),
            'contacts' => $this->topContacts(),
        ];
    }

    private function tags(): array
    {
        $current = $this->tagCounts($this->scope->from, $this->scope->to);
        $previous = $this->tagCounts($this->scope->previousFrom, $this->scope->previousTo)->keyBy('tag_id');

        $total = (int) $current->sum('total');

        return [
            'total_tagged' => $total,
            'untagged' => $this->untagged(),
            'items' => $current->take(12)->map(function ($row) use ($previous, $total) {
                return [
                    'tag_id' => (int) $row->tag_id,
                    'name' => $row->name,
                    'color' => $row->color,
                    'total' => (int) $row->total,
                    'previous_total' => (int) ($previous[$row->tag_id]->total ?? 0),
                    'percentage' => $total > 0 ? round(((int) $row->total / $total) * 100, 1) : 0.0,
                ];
            })->values()->all(),
        ];
    }

    private function tagCounts(Carbon $from, Carbon $to)
    {
        return $this->scope->conversations($from, $to)
            ->join('conversation_tags', 'conversation_tags.conversation_id', '=', 'conversations.id')
            ->join('tags', 'tags.id', '=', 'conversation_tags.tag_id')
            ->groupBy('tags.id', 'tags.name', 'tags.color')
            ->select('tags.id as tag_id', 'tags.name', 'tags.color', DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Conversations nobody labelled. Reported next to the tag chart because a
     * ranking built from 8% of the period is a ranking of nothing.
     */
    private function untagged(): int
    {
        return $this->scope->conversations($this->scope->from, $this->scope->to)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('conversation_tags')
                    ->whereColumn('conversation_tags.conversation_id', 'conversations.id');
            })
            ->count();
    }

    private function topContacts(): array
    {
        $rows = $this->scope->messages($this->scope->from, $this->scope->to)
            ->join('contacts', 'contacts.id', '=', 'conversations.contact_id')
            ->groupBy('contacts.id', 'contacts.name', 'contacts.channel')
            ->select(
                'contacts.id',
                'contacts.name',
                'contacts.channel',
                DB::raw('COUNT(*) as messages'),
                DB::raw('COUNT(DISTINCT conversations.id) as conversations')
            )
            ->orderByDesc('messages')
            ->limit(10)
            ->get();

        return $rows->map(fn ($row) => [
            'contact_id' => (int) $row->id,
            'name' => $row->name,
            'channel' => $row->channel,
            'messages' => (int) $row->messages,
            'conversations' => (int) $row->conversations,
        ])->all();
    }
}
