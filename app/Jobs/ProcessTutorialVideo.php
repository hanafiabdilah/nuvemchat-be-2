<?php

namespace App\Jobs;

use App\Enums\Tutorial\TutorialVideoStatus;
use App\Exceptions\TutorialVideoException;
use App\Models\TutorialVideo;
use App\Services\Media\MediaStorage;
use App\Services\Message\StagedVideoUploads;
use App\Services\Tutorials\TutorialVideoProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Make an uploaded tutorial playable (TutorialVideoProcessor), then publish it.
 *
 * Queued: an encode of a long recording is minutes of ffmpeg, which is no
 * thing to hold the admin's request open for. The Back Office polls the row
 * meanwhile.
 *
 * The upload waits on the staging disk (`source_path`). A new video is
 * `processing` until this job finishes; a video whose file is being replaced
 * stays `ready` and keeps playing its old file, which is deleted only once the
 * new one is published — a failed replacement leaves the old video playing
 * instead of leaving nothing.
 *
 * `$tries = 1`: a second attempt would encode the same file again for the
 * same reason the first one failed. The admin uploads again instead.
 *
 * ⚠️ An encode can outlast the queue's `retry_after` (90 s by default), and a
 * second worker on the same queue would then receive the job again while the
 * first is still encoding. The in-flight key stops that copy from encoding
 * twice, and from marking the video failed (with tries = 1 the copy arrives
 * as MaxAttemptsExceeded) underneath the encode that is about to succeed.
 */
class ProcessTutorialVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $videoId,
        public string $sourcePath,
    ) {
        // Three ffmpeg runs at most (remux or encode, a fallback encode, the
        // poster), plus the copy to storage.
        $this->timeout = (int) config('media.video.timeout_seconds', 900) * 2 + 120;
        $this->onQueue(config('media.video.queue'));
    }

    public function handle(TutorialVideoProcessor $processor): void
    {
        $video = TutorialVideo::find($this->videoId);

        // Deleted while queued, or replaced again by a newer upload: the newer
        // upload has its own job.
        if (! $video || $video->source_path !== $this->sourcePath) {
            StagedVideoUploads::delete($this->sourcePath);

            return;
        }

        // A copy of a job that is already encoding (see the class note).
        if (! Cache::add($this->inFlightKey(), true, $this->timeout)) {
            return;
        }

        try {
            $result = $processor->process($this->sourcePath);
        } catch (Throwable $e) {
            $this->markFailed($video, $e);

            return;
        } finally {
            Cache::forget($this->inFlightKey());
        }

        $previous = [$video->path, $video->poster_path];

        $video->update([
            'path' => $result['path'],
            'poster_path' => $result['poster_path'],
            'duration_seconds' => $result['duration_seconds'],
            'width' => $result['width'],
            'height' => $result['height'],
            'size_bytes' => $result['size_bytes'],
            'status' => TutorialVideoStatus::Ready,
            'error' => $result['note'],
            'source_path' => null,
            'processed_at' => now(),
        ]);

        StagedVideoUploads::delete($this->sourcePath);
        self::deleteFiles(array_diff(array_filter($previous), [$result['path'], $result['poster_path']]));
    }

    public function failed(?Throwable $exception): void
    {
        // The copy delivered while the first run is still encoding: that run
        // decides how this ends.
        if ($exception instanceof MaxAttemptsExceededException && Cache::has($this->inFlightKey())) {
            return;
        }

        $video = TutorialVideo::find($this->videoId);

        if ($video && $video->source_path === $this->sourcePath) {
            $this->markFailed($video, $exception);
        }
    }

    private function markFailed(TutorialVideo $video, ?Throwable $e): void
    {
        Log::warning('Tutorial video processing failed', [
            'video_id' => $video->id,
            'error' => $e?->getMessage(),
        ]);

        // Our own sentences come through as they are; anything else (a disk
        // error, a timeout) is summarised rather than shown raw.
        $message = $e instanceof TutorialVideoException
            ? $e->getMessage()
            : 'Processing stopped before the video was ready. Upload it again; if it keeps failing, export it as MP4 (H.264).';

        StagedVideoUploads::delete($this->sourcePath);

        // A replacement that failed keeps the video that was playing before.
        if ($video->path !== null) {
            $video->update([
                'source_path' => null,
                'error' => mb_substr('The new file could not be used, so the previous one is still shown. '.$message, 0, 500),
            ]);

            return;
        }

        $video->update([
            'status' => TutorialVideoStatus::Failed,
            'source_path' => null,
            'error' => mb_substr($message, 0, 500),
        ]);
    }

    private function inFlightKey(): string
    {
        return 'tutorial-video:processing:'.$this->videoId.':'.sha1($this->sourcePath);
    }

    /**
     * Delete published files a tutorial owned (and their folder once empty).
     *
     * @param  iterable<string|null>  $paths
     */
    public static function deleteFiles(iterable $paths): void
    {
        $disk = MediaStorage::published();

        foreach ($paths as $path) {
            if (! $path) {
                continue;
            }

            try {
                $disk->delete($path);

                $directory = dirname($path);
                if (str_starts_with($directory, 'tutorials/') && $disk->files($directory) === []) {
                    $disk->deleteDirectory($directory);
                }
            } catch (Throwable $e) {
                Log::warning('Tutorial video file could not be deleted', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }
}
