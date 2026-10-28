<?php

namespace App\Services\Youtube;

use Illuminate\Support\Carbon;

/**
 * Google's resources, reshaped into what the dashboard draws.
 *
 * Instagram's media objects are passed to the SPA under Meta's own field names;
 * YouTube's are not, because they are deeply nested (`snippet.thumbnails.high`,
 * `status.publishAt`, `statistics.viewCount` as a *string*) and every consumer
 * would otherwise re-derive the same five facts. One flat shape, built here.
 */
class YoutubePresenter
{
    /** @return array<string, mixed> */
    public static function video(array $video): array
    {
        $snippet = $video['snippet'] ?? [];
        $status = $video['status'] ?? [];
        $stats = $video['statistics'] ?? [];
        $publishAt = $status['publishAt'] ?? null;

        return [
            'id' => (string) ($video['id'] ?? ''),
            'title' => (string) ($snippet['title'] ?? ''),
            'description' => (string) ($snippet['description'] ?? ''),
            'tags' => array_values($snippet['tags'] ?? []),
            'category_id' => $snippet['categoryId'] ?? null,
            'thumbnail_url' => self::thumbnail($snippet['thumbnails'] ?? []),
            'published_at' => $snippet['publishedAt'] ?? null,
            'privacy_status' => $status['privacyStatus'] ?? null,
            // Present only while a private video waits for YouTube to make it
            // public — the one case the grid veils as "scheduled".
            'publish_at' => $publishAt,
            'is_scheduled' => $publishAt !== null
                && ($status['privacyStatus'] ?? null) === 'private'
                && Carbon::parse($publishAt)->isFuture(),
            // uploaded = still processing; processed = ready; rejected/failed =
            // YouTube will not show it, with the reason below.
            'upload_status' => $status['uploadStatus'] ?? null,
            'problem' => $status['rejectionReason'] ?? $status['failureReason'] ?? null,
            'made_for_kids' => (bool) ($status['selfDeclaredMadeForKids'] ?? $status['madeForKids'] ?? false),
            'duration_seconds' => self::seconds($video['contentDetails']['duration'] ?? null),
            'view_count' => (int) ($stats['viewCount'] ?? 0),
            'like_count' => (int) ($stats['likeCount'] ?? 0),
            // Absent when the owner turned comments off.
            'comment_count' => isset($stats['commentCount']) ? (int) $stats['commentCount'] : null,
            'url' => 'https://www.youtube.com/watch?v='.($video['id'] ?? ''),
        ];
    }

    /**
     * One comment thread, as the Comments section reads it.
     *
     * `from_account` is matched on the author's channel id — unlike Instagram,
     * YouTube gives one, so the "already answered" check is exact.
     *
     * @return array<string, mixed>
     */
    public static function thread(array $thread, string $channelId): array
    {
        $snippet = $thread['snippet'] ?? [];
        $top = $snippet['topLevelComment'] ?? [];

        $replies = array_map(
            fn (array $reply) => self::comment($reply, $channelId),
            $thread['replies']['comments'] ?? [],
        );

        // Oldest first under the comment, the way a thread reads.
        usort($replies, fn (array $a, array $b) => strtotime($a['published_at'] ?? '') <=> strtotime($b['published_at'] ?? ''));

        $comment = self::comment($top, $channelId);

        return $comment + [
            'thread_id' => (string) ($thread['id'] ?? $comment['id']),
            'video_id' => $snippet['videoId'] ?? null,
            'can_reply' => (bool) ($snippet['canReply'] ?? true),
            'reply_count' => (int) ($snippet['totalReplyCount'] ?? count($replies)),
            'replies' => $replies,
            'replied' => collect($replies)->contains('from_account', true),
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(array $comment, string $channelId): array
    {
        $snippet = $comment['snippet'] ?? [];
        $author = $snippet['authorChannelId']['value'] ?? null;

        return [
            'id' => (string) ($comment['id'] ?? ''),
            'text' => (string) ($snippet['textOriginal'] ?? $snippet['textDisplay'] ?? ''),
            'author' => $snippet['authorDisplayName'] ?? null,
            'author_avatar' => $snippet['authorProfileImageUrl'] ?? null,
            'author_channel_id' => $author,
            'author_url' => $snippet['authorChannelUrl'] ?? null,
            'like_count' => (int) ($snippet['likeCount'] ?? 0),
            'published_at' => $snippet['publishedAt'] ?? null,
            'updated_at' => $snippet['updatedAt'] ?? null,
            'moderation_status' => $snippet['moderationStatus'] ?? 'published',
            'from_account' => $author !== null && $author === $channelId,
        ];
    }

    /** The best thumbnail Google offers, largest the grid can use first. */
    public static function thumbnail(array $thumbnails): ?string
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
            if (! empty($thumbnails[$size]['url'])) {
                return $thumbnails[$size]['url'];
            }
        }

        return null;
    }

    /** ISO 8601 duration (PT1H2M3S) → seconds. */
    public static function seconds(?string $iso): ?int
    {
        if (! $iso) {
            return null;
        }

        try {
            $interval = new \DateInterval($iso);

            return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
        } catch (\Throwable) {
            return null;
        }
    }
}
