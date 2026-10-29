<?php

namespace App\Services\TikTok;

/**
 * TikTok's resources in the shape the dashboard draws. TikTok returns counts
 * as numbers and times as epoch seconds; the SPA gets ISO dates and our field
 * names, the same way YoutubePresenter flattens Google's.
 */
class TikTokPresenter
{
    /** @return array<string, mixed> */
    public static function video(array $video): array
    {
        return [
            'id' => (string) ($video['item_id'] ?? ''),
            'media_type' => strtoupper((string) ($video['media_type'] ?? 'VIDEO')),
            'caption' => (string) ($video['caption'] ?? ''),
            // Signed and short-lived (x-expires in the URL): fine for drawing
            // now, never stored.
            'thumbnail_url' => $video['thumbnail_url'] ?? null,
            'share_url' => $video['share_url'] ?? null,
            'embed_url' => $video['embed_url'] ?? null,
            'duration_seconds' => isset($video['video_duration']) ? (float) $video['video_duration'] : null,
            'view_count' => (int) ($video['video_views'] ?? 0),
            'like_count' => (int) ($video['likes'] ?? 0),
            'comment_count' => (int) ($video['comments'] ?? 0),
            'share_count' => (int) ($video['shares'] ?? 0),
            'created_at' => isset($video['create_time']) ? date(DATE_ATOM, (int) $video['create_time']) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(array $comment): array
    {
        $replies = array_map(fn (array $reply) => self::reply($reply), $comment['reply_list'] ?? []);

        return self::reply($comment) + [
            'reply_count' => (int) ($comment['replies'] ?? count($replies)),
            'pinned' => (bool) ($comment['pinned'] ?? false),
            'replies' => $replies,
            // The account answered it: one of its own replies is under it.
            'replied' => collect($replies)->contains('from_account', true),
        ];
    }

    /** @return array<string, mixed> */
    public static function reply(array $comment): array
    {
        return [
            'id' => (string) ($comment['comment_id'] ?? ''),
            'video_id' => isset($comment['video_id']) ? (string) $comment['video_id'] : null,
            'parent_id' => ! empty($comment['parent_comment_id']) ? (string) $comment['parent_comment_id'] : null,
            'text' => (string) ($comment['text'] ?? ''),
            'image_url' => $comment['image_url'] ?? null,
            'username' => $comment['username'] ?? null,
            'display_name' => $comment['display_name'] ?? null,
            'avatar' => $comment['profile_image'] ?? null,
            'like_count' => (int) ($comment['likes'] ?? 0),
            'liked' => (bool) ($comment['liked'] ?? false),
            'hidden' => strtoupper((string) ($comment['status'] ?? 'PUBLIC')) === 'HIDDEN',
            // TikTok says so directly: whether the video's owner wrote it.
            'from_account' => (bool) ($comment['owner'] ?? false),
            'created_at' => isset($comment['create_time']) ? date(DATE_ATOM, (int) $comment['create_time']) : null,
        ];
    }
}
