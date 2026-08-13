<?php

namespace App\Console\Commands\Instagram;

use App\Enums\Instagram\PostStatus;
use App\Jobs\PublishInstagramPost;
use App\Models\InstagramPost;
use App\Services\Instagram\InstagramPostPublisher;
use Illuminate\Console\Command;

/**
 * The minute hand for scheduled posts: fires the ones that are due, and picks
 * up the ones whose publish chain died.
 *
 * The recovery half matters more here than the count of due posts. Publishing
 * is a chain of self-re-dispatching jobs with no retries, so a worker killed
 * while Meta was transcoding leaves a post stuck in `publishing` with nothing
 * left to advance it — and unlike a failed send, nobody finds out, because from
 * the dashboard it looks like Instagram is simply taking a while.
 */
class PublishScheduledPosts extends Command
{
    protected $signature = 'instagram:publish-scheduled';

    protected $description = 'Publish due Instagram posts and revive stalled ones';

    /**
     * A live chain re-queues itself at most a minute apart, so silence for
     * several minutes means the chain is gone rather than merely slow.
     */
    private const STALE_MINUTES = 5;

    public function handle(): int
    {
        $this->dispatchDue();
        $this->reviveStalled();

        return self::SUCCESS;
    }

    private function dispatchDue(): void
    {
        InstagramPost::where('status', PostStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->each(function (InstagramPost $post) {
                // Stamped before dispatching: a queue working through a backlog
                // would otherwise leave the post Scheduled and due, and every
                // tick would put another copy of the job behind it.
                $post->update(['status' => PostStatus::Queued]);

                PublishInstagramPost::dispatch($post->id);
                $this->info("Dispatched scheduled Instagram post #{$post->id}");
            });
    }

    /**
     * Posts left mid-publish by a dead worker.
     *
     * Safe to re-dispatch because the publisher is re-entrant: it reuses the
     * container ids already recorded on the post and its carousel items instead
     * of building new ones, so reviving a post cannot publish it twice.
     *
     * Posts that have burned through their attempts are failed outright — they
     * would otherwise be re-dispatched every minute forever, and the publisher
     * would refuse each time.
     */
    private function reviveStalled(): void
    {
        // Queued is swept too: a post stamped for the queue whose worker never
        // arrived (or died before claiming it) is just as stuck as one abandoned
        // mid-publish, and looks identical from the dashboard.
        InstagramPost::whereIn('status', [PostStatus::Queued, PostStatus::Publishing])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->get()
            ->each(function (InstagramPost $post) {
                if ($post->attempts > InstagramPostPublisher::MAX_ATTEMPTS) {
                    $post->update([
                        'status' => PostStatus::Failed,
                        'error' => 'Instagram did not finish processing this post in time.',
                    ]);
                    $this->warn("Gave up on stalled Instagram post #{$post->id}");

                    return;
                }

                PublishInstagramPost::dispatch($post->id);
                $this->info("Revived stalled Instagram post #{$post->id}");
            });
    }
}
