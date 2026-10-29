<?php

namespace App\Jobs;

use App\Enums\TikTok\PostStatus;
use App\Exceptions\TikTokApiException;
use App\Models\TikTokPost;
use App\Services\TikTok\TikTokPostPublisher;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Carries one post through TikTok's publish, re-queueing itself while TikTok
 * downloads and moderates. `$tries = 1` and a locked claim, exactly as
 * PublishInstagramPost: a retry here is a second post.
 */
class PublishTikTokPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $postId)
    {
        $this->onQueue(config('queue.media'));
    }

    public function handle(TikTokPostPublisher $publisher): void
    {
        $post = $this->claim();

        if (! $post) {
            return;
        }

        try {
            if ($publisher->attempt($post)) {
                Log::info('TikTok post published', ['tiktok_post_id' => $post->id, 'item_id' => $post->tiktok_item_id]);

                return;
            }

            self::dispatch($post->id)->delay(now()->addSeconds($this->backoffSeconds($post)));
        } catch (TikTokApiException $e) {
            // Throttled before TikTok took the post (no share_id yet): nothing
            // was published, so trying again later cannot duplicate it.
            if ($e->isRateLimited() && ! $post->share_id && $post->attempts < TikTokPostPublisher::MAX_ATTEMPTS) {
                self::dispatch($post->id)->delay(now()->addMinute());

                return;
            }

            $this->fail($post, UpstreamError::message(
                UpstreamProvider::TikTok,
                $e->getMessage(),
                $e->tiktokCode(),
                $e->httpStatus(),
                ['tiktok_post_id' => $post->id],
            ));
        } catch (\Throwable $e) {
            Log::error('TikTok publish failed', ['tiktok_post_id' => $post->id, 'error' => $e->getMessage()]);

            $this->fail($post, 'Something went wrong while publishing this post.');
        }
    }

    private function claim(): ?TikTokPost
    {
        return DB::transaction(function () {
            $post = TikTokPost::with(['items', 'connection'])->lockForUpdate()->find($this->postId);

            if (! $post) {
                return null;
            }

            if ($post->status->isPublishable() || $post->status === PostStatus::Queued) {
                $post->update(['status' => PostStatus::Publishing, 'error' => null]);

                return $post;
            }

            return $post->status === PostStatus::Publishing && $post->attempts > 0 ? $post : null;
        });
    }

    /** Quick at first — a photo is usually done in seconds — then backing off. */
    private function backoffSeconds(TikTokPost $post): int
    {
        return match (true) {
            $post->attempts <= 3 => 10,
            $post->attempts <= 10 => 30,
            default => 60,
        };
    }

    private function fail(TikTokPost $post, string $error): void
    {
        $post->update(['status' => PostStatus::Failed, 'error' => $error]);
    }
}
