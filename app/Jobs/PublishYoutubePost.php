<?php

namespace App\Jobs;

use App\Enums\Youtube\PostStatus;
use App\Events\YoutubeUpdated;
use App\Exceptions\YoutubeApiException;
use App\Models\YoutubePost;
use App\Services\Youtube\YoutubeUploader;
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
 * Carries one video up to YouTube, re-queueing itself between slices.
 *
 * `$tries = 1`, like PublishInstagramPost: what deserves another go is decided
 * by the uploader (which knows the session and the confirmed offset) and
 * re-queued explicitly. The claim is a conditional update under a row lock so a
 * scheduler revival landing on a live chain does not start a second upload.
 */
class PublishYoutubePost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * One pass sends for YoutubeUploader::PASS_SECONDS plus one chunk that may
     * already be on the wire, and a remote file is copied down first. Kept
     * under the media worker's --timeout=300: a job that overruns its worker
     * kills the worker and every job queued behind it.
     */
    public int $timeout = 280;

    public function __construct(public int $postId)
    {
        $this->onQueue(config('queue.media'));
    }

    public function handle(YoutubeUploader $uploader): void
    {
        $post = $this->claim();

        if (! $post) {
            return;
        }

        try {
            if ($uploader->attempt($post)) {
                Log::info('YouTube video uploaded', [
                    'youtube_post_id' => $post->id,
                    'yt_video_id' => $post->yt_video_id,
                ]);

                $this->announce($post);

                return;
            }

            // More bytes to send. No delay: the pass ended on its time budget,
            // not because anything is waiting.
            self::dispatch($post->id);
        } catch (YoutubeApiException $e) {
            if ($this->isTransient($e) && $post->attempts < YoutubeUploader::MAX_ATTEMPTS) {
                Log::warning('YouTube upload pass failed, retrying', [
                    'youtube_post_id' => $post->id,
                    'error' => $e->getMessage(),
                ]);

                self::dispatch($post->id)->delay(now()->addSeconds(30));

                return;
            }

            $this->fail($post, UpstreamError::message(
                UpstreamProvider::Youtube,
                $e->getMessage(),
                $e->reason(),
                $e->httpStatus(),
                ['youtube_post_id' => $post->id],
            ));
        } catch (\Throwable $e) {
            Log::error('YouTube upload failed', [
                'youtube_post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            // Network trouble mid-transfer is the common case here, and the
            // session survives it: try again from the confirmed byte.
            if ($post->attempts < YoutubeUploader::MAX_ATTEMPTS) {
                self::dispatch($post->id)->delay(now()->addSeconds(30));

                return;
            }

            $this->fail($post, 'Something went wrong while sending this video to YouTube.');
        }
    }

    private function claim(): ?YoutubePost
    {
        return DB::transaction(function () {
            $post = YoutubePost::with('account')->lockForUpdate()->find($this->postId);

            if (! $post) {
                return null;
            }

            if ($post->status->isPublishable() || $post->status === PostStatus::Queued) {
                $post->update(['status' => PostStatus::Uploading, 'error' => null]);

                return $post;
            }

            // The continuation of the chain that already holds it.
            return $post->status === PostStatus::Uploading && $post->attempts > 0 ? $post : null;
        });
    }

    /** Google's own trouble, or a session to reopen — worth another pass. */
    private function isTransient(YoutubeApiException $e): bool
    {
        return $e->httpStatus() >= 500
            || in_array($e->reason(), ['backendError', 'internalError', 'rateLimitExceeded'], true);
    }

    private function fail(YoutubePost $post, string $error): void
    {
        $post->update([
            'status' => PostStatus::Failed,
            'error' => $error,
        ]);

        $this->announce($post);
    }

    private function announce(YoutubePost $post): void
    {
        try {
            broadcast(new YoutubeUpdated($post->tenant_id, (int) $post->youtube_account_id));
        } catch (\Throwable) {
            // The feed polls while an upload runs; a lost event costs seconds.
        }
    }
}
