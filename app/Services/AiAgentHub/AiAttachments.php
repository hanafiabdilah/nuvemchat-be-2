<?php

namespace App\Services\AiAgentHub;

use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Models\Message;
use App\Services\Media\MediaRetention;
use Illuminate\Support\Collection;

/**
 * Turns the images a customer sent into the `message.attachments` array the
 * hub's POST /v1/runs accepts.
 *
 * The hub fetches the URL itself, so what travels is the same signed link the
 * dashboard renders — no bytes are re-read and nothing is base64-encoded. That
 * link is public-but-signed and stays valid until the file's purge date
 * (App\Services\Media\MediaRetention), which is exactly as long as the run
 * could ever need it.
 */
class AiAttachments
{
    /**
     * The hub only defines `type: image`, so an attachment is worth building
     * only when the bytes behind it are one. Extension is the honest test: it
     * is derived from the channel's declared MIME type at download time, and
     * a screenshot is just as often sent as a "document" as it is as an image.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Message types that can carry an image. Stickers are deliberately absent:
     * they are decoration, they are frequently animated WebP that vision
     * models reject, and paying for a picture of a cartoon thumbs-up on every
     * turn is worse than not seeing it.
     */
    private const IMAGE_CARRIERS = [MessageType::Image, MessageType::Document];

    /**
     * Ceiling on images per run. A customer who fires off eight screenshots is
     * not asking for eight images to be analysed — and every one of them is
     * billed input tokens.
     */
    public const MAX_PER_RUN = 4;

    /**
     * Build the hub payload for a set of messages, newest first, capped.
     *
     * @param  Collection<int, Message>|iterable<Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function forMessages(iterable $messages, int $limit = self::MAX_PER_RUN): array
    {
        $attachments = [];

        foreach ($messages as $message) {
            if (count($attachments) >= $limit) {
                break;
            }

            $attachment = self::forMessage($message);

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * One attachment entry, or null when this message has no image the hub
     * could fetch — no file yet, not an image, or the retention window closed
     * and the URL would resolve to a 403.
     *
     * @return array<string, mixed>|null
     */
    public static function forMessage(Message $message): ?array
    {
        if (! self::hasImage($message)) {
            return null;
        }

        $url = MediaRetention::signedUrl($message->attachment, $message, $message->conversation);

        if ($url === null) {
            return null;
        }

        return array_filter([
            'type' => 'image',
            'url' => $url,
            'detail' => 'auto',
            'name' => self::fileName($message->attachment),
        ], fn ($value) => $value !== null);
    }

    /** True when this message's stored file is an image we can hand over now. */
    public static function hasImage(Message $message): bool
    {
        if (! in_array($message->message_type, self::IMAGE_CARRIERS, true)) {
            return false;
        }

        if (! $message->attachment || $message->attachment_status === AttachmentStatus::Expired) {
            return false;
        }

        return in_array(self::extension($message->attachment), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * True when this message will probably carry an image once its download
     * lands — the signal a caller uses to decide whether stalling the AI for a
     * moment is worth it.
     *
     * Only MessageType::Image qualifies. A pending document is unknowable
     * until the bytes arrive (a 40 MB PDF looks identical to a screenshot from
     * here), and holding a customer's reply hostage to that guess costs more
     * than the occasional missed image-sent-as-a-file.
     */
    public static function awaitingImage(Message $message): bool
    {
        return $message->message_type === MessageType::Image
            && $message->attachment_status === AttachmentStatus::Pending;
    }

    /**
     * A one-line stand-in for a message that carries no text of its own.
     *
     * Something has to be said. The hub's `content` is where the customer's
     * turn goes, and sending "" for a bare screenshot asks the model to answer
     * silence — which it does, badly. Naming the medium at least lets the
     * agent respond to what happened ("I can't listen to audio, could you
     * write it?") instead of inventing a message that was never sent.
     */
    public static function describe(Message $message): string
    {
        $type = $message->message_type?->value ?? 'message';

        return "[{$type}]";
    }

    private static function extension(string $path): string
    {
        // Signed URLs and CDN links carry a query string; pathinfo would read
        // it as part of the extension.
        $path = strtok($path, '?') ?: $path;

        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private static function fileName(string $path): ?string
    {
        $name = basename(strtok($path, '?') ?: $path);

        return $name !== '' ? $name : null;
    }
}
