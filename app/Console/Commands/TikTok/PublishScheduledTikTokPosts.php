<?php

namespace App\Console\Commands\TikTok;

use App\Enums\TikTok\PostStatus;
use App\Jobs\PublishTikTokPost;
use App\Models\TikTokPost;
use App\Services\TikTok\TikTokPostPublisher;
use Illuminate\Console\Command;

/**
 * The minute hand for scheduled TikTok posts: fires the due ones and revives
 * chains whose worker died — the same two halves as instagram:publish-scheduled,
 * because TikTok has no scheduler of its own on this API.
 */
class PublishScheduledTikTokPosts extends Command
{
    protected $signature = 'tiktok:publish-scheduled';

    protected $description = 'Publish due TikTok posts and revive stalled ones';

    private const STALE_MINUTES = 5;

    public function handle(): int
    {
        TikTokPost::where('status', PostStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->each(function (TikTokPost $post) {
                // Stamped first so a busy queue does not get a second copy.
                $post->update(['status' => PostStatus::Queued]);
                PublishTikTokPost::dispatch($post->id);
                $this->info("Dispatched scheduled TikTok post #{$post->id}");
            });

        TikTokPost::whereIn('status', [PostStatus::Queued, PostStatus::Publishing])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->get()
            ->each(function (TikTokPost $post) {
                if ($post->attempts > TikTokPostPublisher::MAX_ATTEMPTS) {
                    $post->update(['status' => PostStatus::Failed, 'error' => 'TikTok did not finish processing this post in time.']);
                    $this->warn("Gave up on stalled TikTok post #{$post->id}");

                    return;
                }

                // Safe: a post with a share_id is polled, never published again.
                $post->touch();
                PublishTikTokPost::dispatch($post->id);
                $this->info("Revived stalled TikTok post #{$post->id}");
            });

        return self::SUCCESS;
    }
}
