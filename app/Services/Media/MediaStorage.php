<?php

namespace App\Services\Media;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Where media bytes live — in three roles, so that moving them to object
 * storage is a configuration change instead of an edit to every channel.
 *
 * - disk(): private media reached through signed links. Message attachments,
 *   avatars, contact photos, widget uploads, e-mail bodies and attachments.
 *   `media.disk` / MEDIA_DISK, default "local".
 *
 * - outbound(): short-lived copies a channel fetches the moment we send
 *   (Instagram, Messenger and API Way sending by URL). Deleted straight after,
 *   so an address valid for a couple of hours is plenty.
 *   `media.outbound_disk` / MEDIA_OUTBOUND_DISK, default "public".
 *
 * - published(): files whose address is written into something that keeps
 *   sending them for months — a flow node's attachment, a carousel card, a
 *   campaign's media (POST /api/uploads), a scheduled Instagram post. That
 *   address must never expire.
 *   `media.published_disk` / MEDIA_PUBLISHED_DISK, default "public".
 *
 * Three roles rather than one setting because they need three different
 * addresses once bytes leave the local disk: a signed link, a presigned URL
 * that lives for hours, and a permanent public URL. A single disk would give
 * two of them the wrong one.
 *
 * Nothing outside this class names a media disk. A test guards that.
 */
final class MediaStorage
{
    /** Private media. */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }

    public static function diskName(): string
    {
        return (string) config('media.disk', 'local');
    }

    /**
     * The link handed out for a private file: our own signed route, whatever
     * disk holds the bytes.
     *
     * Same address and relative signature Laravel's `storage.local` route used,
     * so links already cached in dashboards stay valid. Its lifetime is ours to
     * choose (up to months); the route turns it into a short presigned URL when
     * the file is on object storage, where presigned URLs cannot outlive 7 days.
     */
    public static function signedUrl(string $path, DateTimeInterface $expiresAt): string
    {
        return url(URL::temporarySignedRoute('media.file', $expiresAt, ['path' => $path], absolute: false));
    }

    /**
     * A presigned URL for a private file on object storage.
     *
     * The expiry is rounded to the hour so the address stays the same for an
     * hour: a browser that sees the same URL again uses its cached image
     * instead of downloading it from the bucket once more.
     */
    public static function presignedUrl(string $path): string
    {
        return self::disk()->temporaryUrl($path, now()->startOfHour()->addHours(2));
    }

    /**
     * The disk media used to live on, while it is being moved. A file the
     * signed route cannot find on the current disk is still served from here.
     */
    public static function legacyDisk(): ?FilesystemAdapter
    {
        $name = (string) config('media.legacy_disk');

        return $name === '' || $name === self::diskName() ? null : Storage::disk($name);
    }

    /** Copies a channel fetches while we send. */
    public static function outbound(): FilesystemAdapter
    {
        return Storage::disk(self::outboundDiskName());
    }

    public static function outboundDiskName(): string
    {
        return (string) config('media.outbound_disk', 'public');
    }

    /**
     * An address a channel can fetch right now.
     *
     * On a local disk it is the /storage link on the platform domain — so a
     * local outbound disk must be the one served there (`public`). Anywhere
     * else it is a presigned URL that outlives the send by a wide margin.
     */
    public static function outboundUrl(string $path): string
    {
        if (self::isLocalDisk(self::outboundDiskName())) {
            return url('storage/'.ltrim($path, '/'));
        }

        return self::outbound()->temporaryUrl($path, now()->addHours(2));
    }

    /** Files whose address is stored and reused for months. */
    public static function published(): FilesystemAdapter
    {
        return Storage::disk(self::publishedDiskName());
    }

    public static function publishedDiskName(): string
    {
        return (string) config('media.published_disk', 'public');
    }

    /**
     * An address that keeps working for as long as the file exists.
     *
     * Never a presigned URL: a flow would go on sending a link that died weeks
     * ago. Off the local disk it is the disk's own public URL, which for object
     * storage means its `url` setting (a public bucket path or a CDN).
     */
    public static function publishedUrl(string $path): string
    {
        if (self::isLocalDisk(self::publishedDiskName())) {
            return url('storage/'.ltrim($path, '/'));
        }

        return self::published()->url($path);
    }

    public static function isLocalDisk(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }
}
