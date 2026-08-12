<?php

namespace App\Jobs;

use App\Enums\Message\AttachmentStatus;
use App\Events\MessageUpdated;
use App\Models\Message;
use App\Services\Webhook\Contracts\DownloadsInboundMedia;
use App\Services\Webhook\Factories\ChatFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches one inbound message's media after the message itself is already
 * stored and broadcast.
 *
 * The ingest path used to download the bytes before broadcasting, so an agent
 * saw nothing at all — not even the caption — until the channel's CDN
 * answered, and the webhook request stayed open for the whole round-trip
 * (Meta and Telegram both retry a webhook they consider slow). Now the text
 * lands immediately with `attachment_status = pending`, and this job fills in
 * the file and re-broadcasts the same message over `message-updated`.
 *
 * Clients that were offline for the second broadcast pick the file up anyway:
 * writing `attachment` bumps `updated_at`, which is the cursor the message
 * delta sync reads.
 */
class DownloadInboundMedia implements ShouldQueue
{
    use Queueable;

    /**
     * A failed download is usually the CDN being slow, not the media being
     * gone, so it is worth a couple of attempts before the bubble is marked
     * broken. Permanently missing media costs the same three attempts — rare
     * enough, and bounded.
     */
    public int $tries = 3;

    /** Comfortably above the per-request timeouts the handlers set. */
    public int $timeout = 180;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public Message $message) {}

    /**
     * Mark the message as awaiting media and queue the fetch.
     *
     * Call this *before* broadcasting MessageReceived — the flag has to be on
     * the model the dashboard receives, or the media bubble has nothing to
     * tell it apart from a message that arrived without a file.
     */
    public static function dispatchFor(Message $message): void
    {
        if (! self::handlerFor($message) instanceof DownloadsInboundMedia) {
            return;
        }

        $message->forceFill(['attachment_status' => AttachmentStatus::Pending])->save();

        self::dispatch($message)
            ->onQueue(config('queue.media', 'default'))
            // Ingest stores the message inside a transaction; without this the
            // worker could look for a row that has not landed yet.
            ->afterCommit();
    }

    public function handle(): void
    {
        $message = $this->message;
        $handler = self::handlerFor($message);

        if (! $handler instanceof DownloadsInboundMedia) {
            return;
        }

        // A retry after a partially-successful attempt, or a duplicate
        // dispatch: the bytes are already on disk.
        if ($message->attachment) {
            $this->settle($message);

            return;
        }

        $handler->downloadMedia($message);
        $message->refresh();

        if (! $message->attachment) {
            // The handlers log why; throwing here is what buys the retries.
            throw new RuntimeException("No attachment produced for message {$message->id}");
        }

        $this->settle($message);
    }

    /**
     * Out of attempts. Leave a marker the dashboard can render as a broken
     * bubble instead of a placeholder that spins forever.
     */
    public function failed(?Throwable $exception): void
    {
        $message = $this->message->fresh();

        if (! $message) {
            return;
        }

        Log::warning('DownloadInboundMedia: giving up on media', [
            'message_id' => $message->id,
            'error' => $exception?->getMessage(),
        ]);

        $message->forceFill(['attachment_status' => AttachmentStatus::Failed])->save();

        $this->broadcastUpdate($message);
    }

    /** The file is on disk: clear the flag and push it to open dashboards. */
    private function settle(Message $message): void
    {
        if ($message->attachment_status !== null) {
            $message->forceFill(['attachment_status' => null])->save();
        }

        $this->broadcastUpdate($message);
    }

    private function broadcastUpdate(Message $message): void
    {
        broadcast(new MessageUpdated($message->load('reactions.contact', 'repliedMessage')));
    }

    /**
     * The channel's own handler knows how to read its media; channels without
     * one (email, live chat widget) never reach here.
     */
    private static function handlerFor(Message $message): mixed
    {
        $channel = $message->conversation?->connection?->channel;

        if (! $channel) {
            return null;
        }

        try {
            return ChatFactory::make($channel);
        } catch (Throwable) {
            return null;
        }
    }
}
