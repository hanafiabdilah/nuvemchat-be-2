<?php

namespace App\Services\TikTok;

use App\Enums\TikTok\PostStatus;
use App\Exceptions\TikTokApiException;
use App\Models\TikTokPost;

/**
 * Drives a TikTok publish, one step per call: hand TikTok the URL, then ask
 * how it is going until it says done or failed.
 *
 * Re-entrant for the same reason as InstagramPostPublisher — TikTok downloads
 * the file and moderates it on its own schedule, and a worker sleeping through
 * that is a worker doing nothing. `share_id` is recorded the moment TikTok
 * returns it, so a later pass polls the same task instead of publishing again;
 * a second publish would be a second post.
 */
class TikTokPostPublisher
{
    /** Passes before giving up; with the job's backoff, well over an hour. */
    public const MAX_ATTEMPTS = 60;

    public function __construct(
        private readonly TikTokAccountsClientFactory $clients,
    ) {}

    /** @return bool true when the post is live */
    public function attempt(TikTokPost $post): bool
    {
        $connection = $post->connectionModel();

        if (! $connection) {
            throw new TikTokApiException('The TikTok connection for this post no longer exists.', 'connection_missing', 422);
        }

        $client = $this->clients->for($connection);
        $client->assertPostPermissions();

        $post->increment('attempts');

        if ($post->attempts > self::MAX_ATTEMPTS) {
            throw new TikTokApiException('TikTok did not finish processing this post in time.', 'publish_timeout', 422);
        }

        if (! $post->share_id) {
            $post->update(['share_id' => $this->send($post, $client)]);

            // TikTok has only started downloading; nothing to read back yet.
            return false;
        }

        $status = $client->publishStatus($post->share_id);

        return match ($status['status'] ?? null) {
            'PUBLISH_COMPLETE' => $this->complete($post, $status),
            'FAILED' => throw new TikTokApiException(
                (string) ($status['reason'] ?? 'TikTok could not publish this post.'),
                (string) ($status['reason'] ?? 'publish_failed'),
                422,
            ),
            default => false,
        };
    }

    private function send(TikTokPost $post, TikTokAccountsClient $client): string
    {
        $urls = $post->items->pluck('url')->all();

        if ($urls === []) {
            throw new TikTokApiException('This post has no media to publish.', 'no_media', 422);
        }

        // Checked here rather than left to TikTok: an unverified address fails
        // there minutes later with a reason about the file, which reads as the
        // customer's video being wrong when it is our host that is unproven.
        foreach ($urls as $url) {
            if (! TikTokUrlProperty::allows($url)) {
                throw new TikTokApiException('The media address is not verified with TikTok.', 'url_unverified', 422);
            }
        }

        $shared = [
            'is_brand_organic' => (bool) $post->is_brand_organic,
            'is_branded_content' => (bool) $post->is_branded_content,
            'disable_comment' => (bool) $post->disable_comment,
        ];

        if ($post->isPhoto()) {
            return $client->publishPhoto($urls, array_filter([
                'title' => $post->title,
                'caption' => $post->caption,
                'privacy_level' => $post->privacy_level ?: 'PUBLIC_TO_EVERYONE',
                'auto_add_music' => (bool) $post->auto_add_music,
            ], fn ($value) => $value !== null && $value !== '') + $shared);
        }

        return $client->publishVideo($urls[0], array_filter([
            'caption' => $post->caption,
        ], fn ($value) => $value !== null && $value !== '') + $shared + [
            'disable_duet' => (bool) $post->disable_duet,
            'disable_stitch' => (bool) $post->disable_stitch,
            'is_ai_generated' => (bool) $post->is_ai_generated,
        ]);
    }

    private function complete(TikTokPost $post, array $status): bool
    {
        $post->update([
            'status' => PostStatus::Published,
            'published_at' => now(),
            // May be missing for a few minutes after completion; the grid reads
            // the live list anyway, so this is only the receipt.
            'tiktok_item_id' => isset($status['post_ids'][0]) ? (string) $status['post_ids'][0] : null,
            'error' => null,
        ]);

        return true;
    }
}
