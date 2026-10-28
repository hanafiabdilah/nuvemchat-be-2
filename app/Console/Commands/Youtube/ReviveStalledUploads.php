<?php

namespace App\Console\Commands\Youtube;

use App\Enums\Youtube\PostStatus;
use App\Jobs\PublishYoutubePost;
use App\Models\YoutubePost;
use App\Services\Youtube\YoutubeChunkedUpload;
use App\Services\Youtube\YoutubeUploader;
use Illuminate\Console\Command;

/**
 * Picks up uploads whose chain died.
 *
 * There is nothing here to fire on a schedule — YouTube publishes a scheduled
 * video itself, from `publishAt` — so this command's only job is the one the
 * Instagram scheduler has as its second half: an upload is a chain of
 * self-dispatching jobs with no retries, and a worker killed mid-transfer
 * leaves a video stuck "uploading" with nothing left to move it.
 */
class ReviveStalledUploads extends Command
{
    protected $signature = 'youtube:revive-uploads';

    protected $description = 'Re-dispatch YouTube uploads whose worker died';

    /** A live chain writes progress at least once per pass (≤ ~2 min). */
    private const STALE_MINUTES = 5;

    public function handle(): int
    {
        YoutubePost::whereIn('status', [PostStatus::Queued, PostStatus::Uploading])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->get()
            ->each(function (YoutubePost $post) {
                if ($post->attempts >= YoutubeUploader::MAX_ATTEMPTS) {
                    $post->update([
                        'status' => PostStatus::Failed,
                        'error' => 'The upload did not finish in time. Please try again.',
                    ]);
                    $this->warn("Gave up on stalled YouTube upload #{$post->id}");

                    return;
                }

                // Touched first, so the next tick does not dispatch it again
                // while this copy is still waiting in a busy queue. Safe to
                // resume: the uploader asks the session where it stands.
                $post->touch();

                PublishYoutubePost::dispatch($post->id);
                $this->info("Revived stalled YouTube upload #{$post->id}");
            });

        // Videos the browser started sending and never finished.
        if ($swept = YoutubeChunkedUpload::sweepStale()) {
            $this->info("Removed {$swept} abandoned YouTube upload piece(s)");
        }

        return self::SUCCESS;
    }
}
