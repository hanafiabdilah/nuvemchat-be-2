<?php

namespace App\Jobs;

use App\Enums\Instagram\PostStatus;
use App\Exceptions\InstagramApiException;
use App\Models\InstagramPost;
use App\Services\Instagram\InstagramPostPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Carries one post through Meta's publish, re-queueing itself while Meta works.
 *
 * `$tries = 1` for the same reason RunBroadcastJob uses it: a retry here is a
 * second post, not a second attempt. Everything that deserves another go is
 * decided inside the publisher, which knows whether the container already
 * exists, and re-queued explicitly below.
 *
 * The claim is a conditional UPDATE rather than a read-then-write. Two things
 * can dispatch this — the scheduler tick and a user pressing Publish — and
 * without the claim, a tick that overlaps a click would build two containers
 * and publish the post twice.
 */
class PublishInstagramPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** One pass is a handful of Graph calls; the waiting happens between passes. */
    public int $timeout = 120;

    public function __construct(public int $postId)
    {
        // Shares the media queue: both are slow, network-bound work that must
        // not sit in front of an agent's outgoing reply.
        $this->onQueue(config('queue.media'));
    }

    public function handle(InstagramPostPublisher $publisher): void
    {
        $post = $this->claim();

        if (! $post) {
            return;
        }

        try {
            if ($publisher->attempt($post)) {
                Log::info('Instagram post published', [
                    'instagram_post_id' => $post->id,
                    'ig_media_id' => $post->ig_media_id,
                ]);

                return;
            }

            // Meta is still fetching or transcoding. Come back and look again.
            self::dispatch($post->id)->delay(now()->addSeconds($this->backoffSeconds($post)));
        } catch (InstagramApiException $e) {
            $this->fail($post, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Instagram publish failed', [
                'instagram_post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($post, 'Something went wrong while publishing this post.');
        }
    }

    /**
     * Take ownership of the post, or return null if someone else has it.
     *
     * Publishing is re-entrant, so a post already in Publishing may legitimately
     * be picked up again by the next pass of this same chain — that is the
     * `attempts > 0` arm. What is excluded is a *fresh* dispatch landing on a
     * post another chain is already carrying.
     */
    private function claim(): ?InstagramPost
    {
        return DB::transaction(function () {
            $post = InstagramPost::with(['items', 'connection'])
                ->lockForUpdate()
                ->find($this->postId);

            if (! $post) {
                return null;
            }

            if ($post->status->isPublishable()) {
                $post->update(['status' => PostStatus::Publishing, 'error' => null]);

                return $post;
            }

            // Mid-flight continuation of the chain that already claimed it.
            return $post->status === PostStatus::Publishing && $post->attempts > 0
                ? $post
                : null;
        });
    }

    /**
     * How long to wait before looking at the container again.
     *
     * Quick at first — a short clip is often ready inside half a minute — then
     * backing off, because a reel that is still transcoding after five minutes
     * will not be helped by asking more often.
     */
    private function backoffSeconds(InstagramPost $post): int
    {
        return match (true) {
            $post->attempts <= 3 => 10,
            $post->attempts <= 10 => 30,
            default => 60,
        };
    }

    private function fail(InstagramPost $post, string $error): void
    {
        $post->update([
            'status' => PostStatus::Failed,
            'error' => $error,
        ]);
    }
}
