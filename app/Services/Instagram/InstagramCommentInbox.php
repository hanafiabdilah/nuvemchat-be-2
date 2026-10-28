<?php

namespace App\Services\Instagram;

use App\Models\Connection;

/**
 * An account's Comments section: the comments on its latest posts, merged into
 * one list, newest first — so answering them is a pass down one column instead
 * of opening post after post.
 *
 * Nothing is stored, like the rest of comment moderation: one Graph call per
 * page (comments ride on the media call, see
 * InstagramGraphClient::mediaWithComments), so the list is as current as
 * Instagram is. A failure is left to propagate — the InstagramApiException
 * renderer already turns a missing scope into `instagram_permission_required`,
 * the one failure the page can offer a fix for.
 */
class InstagramCommentInbox
{
    /** How many of the account's latest posts are read per page. */
    public const POSTS_PER_PAGE = 25;

    /** The newest comments read on each of those posts. */
    public const COMMENTS_PER_POST = 50;

    public function __construct(
        private readonly InstagramGraphClientFactory $clients,
    ) {}

    /**
     * @param  string|null  $after  Instagram's cursor to the account's older posts
     * @return array{data: list<array<string, mixed>>, posts: list<array<string, mixed>>, next_cursor: ?string, has_more: bool, window: array<string, int>}
     */
    public function read(Connection $connection, ?string $after = null): array
    {
        $page = $this->clients->for($connection)->mediaWithComments(
            self::POSTS_PER_PAGE,
            self::COMMENTS_PER_POST,
            $after,
        );

        $username = $connection->credentials['username'] ?? null;
        $posts = [];
        $comments = [];

        foreach ($page['data'] ?? [] as $media) {
            $rows = $media['comments']['data'] ?? [];

            // A post nobody commented on has nothing to moderate, and listing
            // it would only lengthen the post filter.
            if ($rows === []) {
                continue;
            }

            $post = $media;
            unset($post['comments']);
            $post['thumbnail'] = self::thumbnail($media);
            $post['comments_loaded'] = count($rows);
            $posts[] = $post;

            foreach ($rows as $row) {
                $comments[] = $this->comment($row, $media['id'], $username);
            }
        }

        usort($comments, fn (array $a, array $b) => strtotime($b['timestamp'] ?? '') <=> strtotime($a['timestamp'] ?? ''));

        return [
            'data' => $comments,
            'posts' => $posts,
            'next_cursor' => $page['paging']['cursors']['after'] ?? null,
            'has_more' => isset($page['paging']['next']),
            'window' => [
                'posts_per_page' => self::POSTS_PER_PAGE,
                'comments_per_post' => self::COMMENTS_PER_POST,
            ],
        ];
    }

    /** The picture a post is recognised by: the cover of a video or a carousel's first item. */
    public static function thumbnail(array $media): ?string
    {
        if (($media['media_type'] ?? null) === 'VIDEO') {
            return $media['thumbnail_url'] ?? null;
        }

        if (($media['media_type'] ?? null) === 'CAROUSEL_ALBUM') {
            $first = $media['children']['data'][0] ?? [];

            return ($first['media_type'] ?? null) === 'VIDEO'
                ? ($first['thumbnail_url'] ?? null)
                : ($first['media_url'] ?? $media['media_url'] ?? null);
        }

        return $media['media_url'] ?? $media['thumbnail_url'] ?? null;
    }

    /**
     * One comment as the Comments section needs it: which post it is on, and
     * whether the account already answered it.
     *
     * "Answered" is a reply written by the account itself — the one fact a
     * moderator scans for. Matched on the username, which is what the Graph API
     * returns for a reply (there is no author id on this edge).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function comment(array $row, string $mediaId, ?string $accountUsername): array
    {
        $isAccount = fn (?string $username) => $accountUsername !== null && $username !== null
            && strcasecmp($username, $accountUsername) === 0;

        $replies = array_map(fn (array $reply) => [
            'id' => $reply['id'],
            'text' => $reply['text'] ?? '',
            'username' => $reply['username'] ?? null,
            'timestamp' => $reply['timestamp'] ?? null,
            'like_count' => (int) ($reply['like_count'] ?? 0),
            'hidden' => (bool) ($reply['hidden'] ?? false),
            'from_account' => $isAccount($reply['username'] ?? null),
        ], $row['replies']['data'] ?? []);

        // Oldest first under the comment, the way a thread reads.
        usort($replies, fn (array $a, array $b) => strtotime($a['timestamp'] ?? '') <=> strtotime($b['timestamp'] ?? ''));

        return [
            'id' => $row['id'],
            'text' => $row['text'] ?? '',
            'username' => $row['username'] ?? null,
            'timestamp' => $row['timestamp'] ?? null,
            'like_count' => (int) ($row['like_count'] ?? 0),
            'hidden' => (bool) ($row['hidden'] ?? false),
            'from_account' => $isAccount($row['username'] ?? null),
            'replied' => collect($replies)->contains('from_account', true),
            'replies' => $replies,
            'media_id' => $mediaId,
        ];
    }
}
