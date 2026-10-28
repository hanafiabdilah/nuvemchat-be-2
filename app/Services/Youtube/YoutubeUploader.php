<?php

namespace App\Services\Youtube;

use App\Enums\Youtube\PostStatus;
use App\Exceptions\YoutubeApiException;
use App\Models\YoutubePost;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Log;

/**
 * Carries one video to YouTube, a slice per call.
 *
 * Uses the resumable protocol rather than one big request, for the same reason
 * the Instagram publisher is re-entrant: a 1 GB file is minutes of a worker's
 * time, and a worker that holds one upload for all of it is a worker every
 * other tenant's job waits behind. So each call sends chunks for a bounded
 * time, records the byte YouTube confirmed, and returns; the job re-queues
 * itself for the rest.
 *
 * The session URI is the safety. Nothing is created on YouTube until the final
 * byte lands, and a pass that resumes always resumes the *same* session — so a
 * worker killed mid-upload, a scheduler revival and a retry all end in exactly
 * one video, never two.
 */
class YoutubeUploader
{
    /**
     * Passes before giving up. Each pass sends for up to PASS_SECONDS, so this
     * is well over an hour of transfer — far more than any file the upload
     * endpoint accepts needs — and bounded, so a wedged upload surfaces as
     * failed the same day instead of retrying forever.
     */
    public const MAX_ATTEMPTS = 40;

    /** A multiple of 256 KiB, as the protocol requires for every chunk but the last. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /** How long one pass keeps sending before handing the worker back. */
    public const PASS_SECONDS = 90;

    public function __construct(
        private readonly YoutubeClientFactory $clients,
    ) {}

    /**
     * Advance the upload.
     *
     * @return bool true once the video exists on YouTube
     */
    public function attempt(YoutubePost $post): bool
    {
        $account = $post->account;

        if (! $account) {
            throw new YoutubeApiException('The YouTube channel for this video is no longer connected.', 'channel_missing', 422);
        }

        $post->increment('attempts');

        if ($post->attempts > self::MAX_ATTEMPTS) {
            throw new YoutubeApiException('The upload did not finish in time. Please try again.', 'upload_timeout', 422);
        }

        $disk = MediaStorage::disk();

        if (! $post->video_path || ! $disk->exists($post->video_path)) {
            throw new YoutubeApiException('The video file is missing. Edit the post and upload the video again.', 'file_missing', 422);
        }

        $client = $this->clients->for($account);
        $size = $post->video_size ?: (int) $disk->size($post->video_path);
        $mime = $post->video_mime ?: 'video/*';

        $offset = $this->resume($post, $client, $size, $mime);

        if ($offset === null) {
            return true; // The last byte had already landed; the video exists.
        }

        [$file, $temporary] = $this->localCopy($post->video_path);

        try {
            $handle = fopen($file, 'rb');

            if ($handle === false) {
                throw new YoutubeApiException('The video file could not be read.', 'file_unreadable', 422);
            }

            try {
                return $this->send($post, $client, $handle, $offset, $size, $mime);
            } finally {
                fclose($handle);
            }
        } finally {
            if ($temporary) {
                @unlink($file);
            }
        }
    }

    /**
     * The byte to send next — opening a session if there is none, asking
     * YouTube where an existing one stands otherwise. Null when the session is
     * already complete (a previous pass sent everything and lost the answer).
     */
    private function resume(YoutubePost $post, YoutubeClient $client, int $size, string $mime): ?int
    {
        if ($post->upload_url) {
            try {
                $status = $client->uploadStatus($post->upload_url, $size);

                if ($status['done']) {
                    $this->finish($post, $status['video'] ?? []);

                    return null;
                }

                $post->update(['uploaded_bytes' => $status['next']]);

                return $status['next'];
            } catch (YoutubeApiException $e) {
                if ($e->reason() !== 'upload_session_expired') {
                    throw $e;
                }

                Log::info('YouTube upload session expired; opening a new one', ['youtube_post_id' => $post->id]);
            }
        }

        $post->update([
            'upload_url' => $client->startUpload($this->metadata($post), $size, $mime, (bool) $post->notify_subscribers),
            'uploaded_bytes' => 0,
        ]);

        return 0;
    }

    /** @param resource $handle */
    private function send(YoutubePost $post, YoutubeClient $client, $handle, int $offset, int $size, string $mime): bool
    {
        $deadline = microtime(true) + self::PASS_SECONDS;

        while (true) {
            fseek($handle, $offset);
            $bytes = $this->read($handle, self::CHUNK_BYTES);

            if ($bytes === '') {
                // Everything YouTube is owed was sent but it did not say so;
                // the next pass asks where the session stands.
                return false;
            }

            $result = $client->putChunk($post->upload_url, $bytes, $offset, $size, $mime);

            if ($result['done']) {
                $this->finish($post, $result['video'] ?? []);

                return true;
            }

            $offset = $result['next'];
            $post->update(['uploaded_bytes' => $offset]);

            if (microtime(true) >= $deadline) {
                return false;
            }
        }
    }

    /** @param resource $handle */
    private function read($handle, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! feof($handle)) {
            $piece = fread($handle, $length - strlen($buffer));

            if ($piece === false || $piece === '') {
                break;
            }

            $buffer .= $piece;
        }

        return $buffer;
    }

    /**
     * What YouTube is told about the video.
     *
     * A schedule is uploaded private with `publishAt`: that is YouTube's own
     * scheduler, and it is why there is no timer of ours to keep alive.
     */
    public function metadata(YoutubePost $post): array
    {
        $status = [
            'privacyStatus' => $post->privacy_status ?: 'public',
            'selfDeclaredMadeForKids' => (bool) $post->made_for_kids,
        ];

        if ($post->isScheduledUpload()) {
            $status['privacyStatus'] = 'private';
            $status['publishAt'] = $post->scheduled_at->copy()->utc()->toIso8601ZuluString();
        }

        return [
            'snippet' => array_filter([
                'title' => $post->title ?: pathinfo((string) $post->video_name, PATHINFO_FILENAME) ?: 'Video',
                'description' => (string) $post->description,
                'tags' => $post->tags ?: null,
                // 22 = People & Blogs, YouTube's own default for uploads.
                'categoryId' => $post->category_id ?: '22',
            ], fn ($value) => $value !== null),
            'status' => $status,
        ];
    }

    /**
     * The video exists: record it, and give the storage back.
     *
     * The file is deleted because it now lives on YouTube — keeping a copy of
     * every video every workspace ever posted would be storage paid for twice.
     */
    private function finish(YoutubePost $post, array $video): void
    {
        $id = $video['id'] ?? null;

        if (! $id) {
            throw new YoutubeApiException('YouTube accepted the upload but returned no video id.');
        }

        $path = $post->video_path;

        $post->update([
            'status' => PostStatus::Published,
            'yt_video_id' => (string) $id,
            'published_at' => now(),
            'upload_url' => null,
            'uploaded_bytes' => (int) $post->video_size,
            'video_path' => null,
            'error' => null,
        ]);

        if ($path) {
            try {
                MediaStorage::disk()->delete($path);
            } catch (\Throwable $e) {
                Log::warning('Could not delete an uploaded YouTube video file', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * A seekable local file for the upload.
     *
     * Object storage hands back streams that cannot seek, and resuming needs
     * to start mid-file, so a remote file is copied down for the pass.
     *
     * @return array{0: string, 1: bool} [path, is temporary]
     */
    private function localCopy(string $path): array
    {
        if (MediaStorage::isLocalDisk(MediaStorage::diskName())) {
            return [MediaStorage::disk()->path($path), false];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'yt-upload-');
        $source = MediaStorage::disk()->readStream($path);
        $target = fopen($temporary, 'wb');

        stream_copy_to_stream($source, $target);
        fclose($target);

        if (is_resource($source)) {
            fclose($source);
        }

        return [$temporary, true];
    }
}
