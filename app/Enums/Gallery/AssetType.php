<?php

namespace App\Enums\Gallery;

use App\Enums\Message\MessageType;

/**
 * What kind of file a gallery asset is.
 *
 * Four cases rather than a free MIME string because every reader asks the same
 * coarse question: which send endpoint takes it, which tile shape renders it,
 * which filter chip holds it. The exact MIME is kept on the row for the
 * channels that need it.
 *
 * `document` is the floor, not a category: anything that is not recognisably
 * a picture, a video or a sound is still a file somebody may want to send.
 */
enum AssetType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /**
     * Classify by MIME type, falling back to the extension.
     *
     * The extension is the fallback rather than the source because browsers
     * and phones lie about it more often than they lie about the MIME type —
     * but a MIME of `application/octet-stream` (what several clients send for
     * anything they do not recognise) carries no information at all, and there
     * the extension is all there is.
     */
    public static function classify(?string $mimeType, ?string $extension = null): self
    {
        $mime = strtolower(trim((string) $mimeType));

        if ($mime !== '' && $mime !== 'application/octet-stream') {
            return match (true) {
                str_starts_with($mime, 'image/') => self::Image,
                str_starts_with($mime, 'video/') => self::Video,
                str_starts_with($mime, 'audio/') => self::Audio,
                default => self::Document,
            };
        }

        return match (strtolower((string) $extension)) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif' => self::Image,
            'mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v', '3gp' => self::Video,
            'mp3', 'ogg', 'oga', 'opus', 'wav', 'm4a', 'aac', 'flac', 'amr' => self::Audio,
            default => self::Document,
        };
    }

    /**
     * The send endpoint this asset goes out through.
     *
     * The gallery does not have a sender of its own: picking a file resolves to
     * one of the four `send-*` routes the composer already calls, with the
     * asset's public URL as `media_url`. Keeping the mapping here means the
     * frontend and the tests agree with the backend about which route that is.
     */
    public function sendPath(): string
    {
        return match ($this) {
            self::Image => 'send-image',
            self::Video => 'send-video',
            self::Audio => 'send-audio',
            self::Document => 'send-document',
        };
    }

    public function messageType(): MessageType
    {
        return match ($this) {
            self::Image => MessageType::Image,
            self::Video => MessageType::Video,
            self::Audio => MessageType::Audio,
            self::Document => MessageType::Document,
        };
    }
}
