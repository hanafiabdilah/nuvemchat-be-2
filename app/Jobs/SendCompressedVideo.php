<?php

namespace App\Jobs;

use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Exceptions\VideoCompressionException;
use App\Models\Conversation;
use App\Models\GalleryAsset;
use App\Models\User;
use App\Services\Conversation\SystemMessage;
use App\Services\Media\VideoCompressor;
use App\Services\Message\MessageService;
use App\Services\Message\StagedVideoUploads;
use App\Services\Messaging\MessagingWindow;
use App\Support\Errors\HasUserSafeMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Compress a video an agent sent from the composer, then send it.
 *
 * Queued because a long video takes minutes of ffmpeg — no thing to hold the
 * agent's request and a PHP worker open for, and a proxy would cut the request
 * long before. The composer shows a "being prepared" bubble meanwhile; what
 * replaces it is the message this job sends, matched by `client_ref`, or the
 * note this job writes when it cannot.
 *
 * Everything the controller checked is checked again here, because minutes
 * have passed: the thread may be resolved, or taken by somebody else.
 *
 * ⚠️ `$tries = 1`. A second attempt is not a second attempt at one send, it is
 * a second video in front of the customer. The cache key at the top makes the
 * same holds for a job delivered twice (a queue's retry_after shorter than an
 * encode).
 */
class SendCompressedVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array{kind: 'staged'|'gallery', path?: string, asset_id?: int, name: string, size: int}  $source
     * @param  array{message?: ?string, view_once?: bool, replied_message_id?: ?int}  $payload
     */
    public function __construct(
        public int $conversationId,
        public int $userId,
        public array $source,
        public array $payload,
        public string $clientRef,
    ) {
        // One ffmpeg run, plus room to read the source and hand the result to
        // the channel.
        $this->timeout = (int) config('media.video.timeout_seconds', 900) + 300;
        $this->onQueue((string) config('media.video.queue', 'default'));
    }

    public function handle(VideoCompressor $compressor): void
    {
        if (! Cache::add('video-send:'.$this->conversationId.':'.$this->clientRef, true, now()->addDay())) {
            Log::warning('SendCompressedVideo: delivered twice, the second run does nothing', [
                'conversation_id' => $this->conversationId,
                'client_ref' => $this->clientRef,
            ]);

            return;
        }

        $conversation = null;
        $local = null;
        $ownsLocal = false;
        $file = null;

        try {
            $conversation = Conversation::with('connection')->find($this->conversationId);

            if (! $conversation) {
                return;
            }

            $user = User::find($this->userId);

            if (! $user || $conversation->status !== Status::Active || ! $conversation->isAccessibleBy($user)) {
                $this->reportFailure($conversation, 'conversation_closed');

                return;
            }

            // The send endpoint checked the reply window minutes ago; a
            // WhatsApp Official window can close while the video encodes.
            if (! MessagingWindow::isOpen($conversation)) {
                $this->reportFailure($conversation, 'window_closed');

                return;
            }

            [$local, $ownsLocal] = $this->materialise();

            if ($local === null) {
                $this->reportFailure($conversation, 'source_missing');

                return;
            }

            $channel = $conversation->connection->channel;
            $limit = $channel->videoMaxBytes();
            $originalBytes = (int) filesize($local);

            $file = VideoCompressor::needsCompression($originalBytes, $limit)
                ? $compressor->fit($local, $limit, $this->source['name'])
                : new UploadedFile($local, $this->source['name'], null, null, true);

            $message = (new MessageService)->sendVideo($conversation, array_filter([
                'video' => $file,
                'message' => $this->payload['message'] ?? null,
                'view_once' => (bool) ($this->payload['view_once'] ?? false),
                'replied_message_id' => $this->payload['replied_message_id'] ?? null,
            ], fn ($value) => $value !== null));

            if (! $message) {
                $this->reportFailure($conversation, 'send_failed');

                return;
            }

            $message->update([
                'sent_by_user_id' => $user->id,
                'meta' => array_merge($message->meta ?? [], [
                    'client_ref' => $this->clientRef,
                    'video_compressed' => [
                        'original_bytes' => $originalBytes,
                        'bytes' => (int) $file->getSize(),
                    ],
                ]),
            ]);

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));
        } catch (VideoCompressionException $e) {
            $this->reportFailure($conversation, $e->reason, $e->maxSeconds);
        } catch (ValidationException $e) {
            $this->reportFailure($conversation, 'send_failed', reason: collect($e->errors())->flatten()->first());
        } catch (HasUserSafeMessage $e) {
            $this->reportFailure($conversation, 'send_failed', reason: $e->getMessage());
        } catch (Throwable $e) {
            Log::error('SendCompressedVideo: failed', [
                'conversation_id' => $this->conversationId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->reportFailure($conversation, 'send_failed');
        } finally {
            $this->cleanUp($local, $ownsLocal, $file);
        }
    }

    /**
     * The worker killed this run (out of time or memory): handle() never got
     * to its own catch. The note still has to be written, or the composer's
     * "being prepared" bubble is all the agent ever sees.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('SendCompressedVideo: the worker gave up on it', [
            'conversation_id' => $this->conversationId,
            'error' => $exception?->getMessage(),
        ]);

        $this->reportFailure(Conversation::with('connection')->find($this->conversationId), 'compression_failed');

        if (($this->source['kind'] ?? null) === 'staged' && isset($this->source['path'])) {
            StagedVideoUploads::delete($this->source['path']);
        }
    }

    /**
     * The file ffmpeg will read: the staged upload where it already is, or a
     * gallery file copied out of the library's disk (which may be a bucket).
     *
     * @return array{0: ?string, 1: bool} the path, and whether it is ours to delete
     */
    private function materialise(): array
    {
        if (($this->source['kind'] ?? null) === 'staged') {
            $path = StagedVideoUploads::absolutePath($this->source['path'] ?? '');

            return [is_file($path) ? $path : null, false];
        }

        $asset = GalleryAsset::find($this->source['asset_id'] ?? 0);

        if (! $asset) {
            return [null, false];
        }

        $stream = Storage::disk((string) config('gallery.disk', 'local'))->readStream($asset->path);

        if (! is_resource($stream)) {
            return [null, false];
        }

        $extension = pathinfo($asset->path, PATHINFO_EXTENSION) ?: 'mp4';
        $local = sys_get_temp_dir().'/'.uniqid('video_src_', true).'.'.$extension;
        $out = fopen($local, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return [$local, true];
    }

    private function cleanUp(?string $local, bool $ownsLocal, ?UploadedFile $file): void
    {
        // The compressed copy is ours; the uploaded original is the staged file.
        if ($file && $file->getRealPath() && $file->getRealPath() !== $local) {
            @unlink($file->getRealPath());
        }

        if ($ownsLocal && $local) {
            @unlink($local);
        }

        if (($this->source['kind'] ?? null) === 'staged' && isset($this->source['path'])) {
            StagedVideoUploads::delete($this->source['path']);
        }
    }

    /**
     * Say in the thread that the video did not go — to the agent who sent it,
     * and to anybody who reads the thread later.
     *
     * The copy is the dashboard's (lib/infoMessage.ts, `video_send_failed`),
     * picked by `reason_code`; `reason` carries our own sentence for a channel
     * refusal the copy has no line for. `client_ref` lets the composer drop the
     * "being prepared" bubble this note answers for.
     */
    private function reportFailure(?Conversation $conversation, string $reasonCode, ?int $maxSeconds = null, ?string $reason = null): void
    {
        if (! $conversation) {
            return;
        }

        try {
            SystemMessage::info($conversation, 'The video could not be sent.', 'video_send_failed', array_filter([
                'reason_code' => $reasonCode,
                'reason' => $reason,
                'max_minutes' => $maxSeconds !== null ? max(1, intdiv($maxSeconds, 60)) : null,
                'name' => $this->source['name'] ?? null,
                'client_ref' => $this->clientRef,
            ], fn ($value) => $value !== null && $value !== ''));

            broadcast(new ConversationUpdated($conversation));
        } catch (Throwable $e) {
            Log::error('SendCompressedVideo: could not write the failure note', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
