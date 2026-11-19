<?php

namespace App\Services\Social;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What is waiting on each account of a social account list (Instagram, TikTok,
 * YouTube): how many posts are scheduled, drafted or failed, and when the next
 * scheduled one goes out.
 *
 * One grouped query (account × status) for the whole list, never one per card.
 * Statuses arrive raw (`toBase()`), so they are compared by value; and
 * `min(scheduled_at)` arrives as whatever string the database spells a
 * timestamp with, so it is parsed here instead of being passed through.
 */
final class PostQueueSummary
{
    /**
     * @param  Builder  $posts  the post model's query
     * @param  string  $owner  column naming the account: `connection_id`, `youtube_account_id`
     * @param  iterable<int|string>  $ownerIds
     * @param  array<int, BackedEnum>  $scheduled  statuses counted as scheduled, and read for the next date
     * @param  array<int, BackedEnum>  $drafts
     * @param  array<int, BackedEnum>  $failed
     * @return array<string, array{scheduled_count: int, draft_count: int, failed_count: int, next_scheduled_at: ?string}>
     */
    public static function build(
        Builder $posts,
        string $owner,
        iterable $ownerIds,
        array $scheduled,
        array $drafts,
        array $failed,
    ): array {
        $ids = collect($ownerIds)->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $values = fn (array $statuses) => array_map(fn (BackedEnum $status) => $status->value, $statuses);
        $scheduledValues = $values($scheduled);
        $draftValues = $values($drafts);
        $failedValues = $values($failed);

        $rows = $posts
            ->whereIn($owner, $ids)
            ->whereIn('status', array_merge($scheduledValues, $draftValues, $failedValues))
            ->selectRaw("{$owner} as owner, status, count(*) as aggregate, min(scheduled_at) as next_at")
            ->groupBy($owner, 'status')
            ->toBase()
            ->get();

        $summary = $ids->mapWithKeys(fn ($id) => [(string) $id => [
            'scheduled_count' => 0,
            'draft_count' => 0,
            'failed_count' => 0,
            'next_scheduled_at' => null,
        ]])->all();

        foreach ($rows as $row) {
            $key = (string) $row->owner;
            $status = (string) $row->status;
            $count = (int) $row->aggregate;

            if (in_array($status, $scheduledValues, true)) {
                $summary[$key]['scheduled_count'] += $count;

                if ($row->next_at !== null) {
                    $next = Carbon::parse($row->next_at);
                    $current = $summary[$key]['next_scheduled_at'];

                    if ($current === null || $next->lt(Carbon::parse($current))) {
                        $summary[$key]['next_scheduled_at'] = $next->toIso8601String();
                    }
                }
            } elseif (in_array($status, $draftValues, true)) {
                $summary[$key]['draft_count'] += $count;
            } elseif (in_array($status, $failedValues, true)) {
                $summary[$key]['failed_count'] += $count;
            }
        }

        return $summary;
    }
}
