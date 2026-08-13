<?php

namespace App\Services\Media;

use App\Enums\Conversation\Type;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * One clock for a message's media: when the file gets deleted, and how long
 * the URL that points at it stays valid.
 *
 * Both answers have to come from the same place. The SPA writes the signed URL
 * into IndexedDB and never asks for it again — nothing re-signs it on a later
 * sync — so a URL that outlives its file leaves a bubble pointing at a 403,
 * and a URL that dies first leaves a bubble that looks broken while the file
 * is still there. Deriving both from `created_at + retention days` makes the
 * two ends meet, and has the side effect of making the URL deterministic: the
 * same message always signs to the same string, so re-syncing a thread no
 * longer busts the browser's image cache.
 */
class MediaRetention
{
    public static function enabled(): bool
    {
        return (bool) config('media.retention.enabled', true);
    }

    /**
     * Days of media retention for a conversation type, or null when nothing is
     * ever purged (retention disabled, or the window configured as 0).
     */
    public static function daysFor(?Type $type): ?int
    {
        if (! self::enabled()) {
            return null;
        }

        $days = $type === Type::Group
            ? (int) config('media.retention.group_days')
            : (int) config('media.retention.private_days');

        return $days > 0 ? $days : null;
    }

    /**
     * The moment this message's file becomes eligible for deletion.
     *
     * Measured from `created_at` — when the bytes landed on our disk — not
     * from `sent_at`, which a history import can backdate by months.
     *
     * Pass $conversation when it is already in hand (the resource has it
     * loaded) to keep this off the query log.
     */
    public static function deadlineFor(Message $message, ?Conversation $conversation = null): ?CarbonImmutable
    {
        $days = self::daysFor(($conversation ?? $message->conversation)?->type);

        if ($days === null || $message->created_at === null) {
            return null;
        }

        return CarbonImmutable::instance($message->created_at)->addDays($days);
    }

    public static function isExpired(Message $message, ?Conversation $conversation = null): bool
    {
        $deadline = self::deadlineFor($message, $conversation);

        return $deadline !== null && $deadline->isPast();
    }

    /**
     * Expiry to sign a media URL with: the purge date, so the link is dead by
     * the time the file is. With retention off there is no purge date, and the
     * URL falls back to a fixed ceiling.
     */
    public static function urlExpiresAt(Message $message, ?Conversation $conversation = null): CarbonImmutable
    {
        $deadline = self::deadlineFor($message, $conversation);

        if ($deadline !== null) {
            return $deadline;
        }

        $createdAt = $message->created_at
            ? CarbonImmutable::instance($message->created_at)
            : CarbonImmutable::now();

        return $createdAt->addDays(max(1, (int) config('media.url_ttl_days', 180)));
    }

    /**
     * Every local storage path this message owns: the attachment column plus
     * the per-file paths an e-mail keeps in meta (only the first of those is
     * mirrored into `attachment`).
     *
     * Attachments stored as absolute URLs are media we never downloaded —
     * someone else pays for that storage, so they are not ours to delete.
     *
     * @return array<int, string>
     */
    public static function localPathsFor(Message $message): array
    {
        $paths = [];

        if ($message->attachment && ! self::isExternal($message->attachment)) {
            $paths[] = $message->attachment;
        }

        foreach (($message->meta['email']['attachments'] ?? []) as $attachment) {
            $path = $attachment['path'] ?? null;

            if (is_string($path) && $path !== '' && ! self::isExternal($path)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    public static function isExternal(string $attachment): bool
    {
        return str_starts_with($attachment, 'http://') || str_starts_with($attachment, 'https://');
    }
}
