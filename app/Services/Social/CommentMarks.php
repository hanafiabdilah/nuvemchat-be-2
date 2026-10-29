<?php

namespace App\Services\Social;

use App\Models\SocialCommentMark;

/**
 * Read and starred marks on platform comments — the two things a team
 * working down a list of comments needs and no platform offers.
 *
 * Marks are the workspace's, not one person's: the Comments sections are a
 * shared inbox, and a comment one moderator has dealt with should stop asking
 * the next one for attention. Marks sit on the top-level comment (the thread);
 * a reply is answered inside its thread, not tracked on its own.
 *
 * The account's own comments are always read — nobody needs to be told about
 * what they wrote themselves.
 */
class CommentMarks
{
    public const PLATFORMS = ['instagram', 'youtube', 'tiktok'];

    /**
     * Add `read` and `starred` to each comment, from what is stored.
     *
     * @param  list<array<string, mixed>>  $comments
     * @return list<array<string, mixed>>
     */
    public function apply(array $comments, int $tenantId, string $platform): array
    {
        $ids = array_values(array_filter(array_map(fn ($comment) => $comment['id'] ?? null, $comments)));

        $marks = $ids === [] ? collect() : SocialCommentMark::query()
            ->where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->whereIn('comment_id', $ids)
            ->get()
            ->keyBy('comment_id');

        return array_map(function (array $comment) use ($marks) {
            $mark = $marks->get($comment['id'] ?? '');

            $comment['read'] = (bool) ($comment['from_account'] ?? false) || $mark?->read_at !== null;
            $comment['starred'] = $mark?->starred_at !== null;

            return $comment;
        }, $comments);
    }

    /**
     * Set either mark; a mark left out is left alone.
     *
     * @return array{read: bool, starred: bool}
     */
    public function set(int $tenantId, string $platform, int $accountId, string $commentId, ?bool $read, ?bool $starred, int $userId): array
    {
        $mark = SocialCommentMark::firstOrNew([
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'comment_id' => $commentId,
        ], ['account_id' => $accountId]);

        if ($read !== null) {
            $mark->read_at = $read ? ($mark->read_at ?? now()) : null;
            $mark->read_by = $read ? $userId : null;
        }

        if ($starred !== null) {
            $mark->starred_at = $starred ? ($mark->starred_at ?? now()) : null;
            $mark->starred_by = $starred ? $userId : null;
        }

        $mark->save();

        return ['read' => $mark->read_at !== null, 'starred' => $mark->starred_at !== null];
    }

    /**
     * Mark several comments read in one go ("mark all as read") — two queries
     * whatever the count, since this is a list of up to a few hundred.
     *
     * @param  list<string>  $commentIds
     */
    public function markRead(int $tenantId, string $platform, int $accountId, array $commentIds, int $userId): int
    {
        $ids = array_values(array_unique(array_map('strval', $commentIds)));

        if ($ids === []) {
            return 0;
        }

        $scope = fn () => SocialCommentMark::query()
            ->where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->whereIn('comment_id', $ids);

        $existing = $scope()->pluck('comment_id')->all();
        $now = now();

        $scope()->whereNull('read_at')->update(['read_at' => $now, 'read_by' => $userId, 'updated_at' => $now]);

        // insertOrIgnore: a moderator marking the same comment in another tab
        // at the same moment loses nothing to the unique index.
        SocialCommentMark::query()->insertOrIgnore(array_map(fn (string $id) => [
            'tenant_id' => $tenantId,
            'platform' => $platform,
            'account_id' => $accountId,
            'comment_id' => $id,
            'read_at' => $now,
            'read_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_diff($ids, $existing))));

        return count($ids);
    }

    /** Best-effort: acting on a comment (replying, hiding…) means it was dealt with. */
    public function touchRead(int $tenantId, string $platform, int $accountId, string $commentId, int $userId): void
    {
        try {
            $this->set($tenantId, $platform, $accountId, $commentId, true, null, $userId);
        } catch (\Throwable) {
            // A mark is never worth failing the action the person took.
        }
    }
}
