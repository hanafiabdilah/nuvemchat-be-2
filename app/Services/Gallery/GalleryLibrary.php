<?php

namespace App\Services\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\AssetType;
use App\Enums\Message\SenderType;
use App\Models\GalleryAsset;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaRetention;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lists files the workspace already has somewhere else in its gallery.
 *
 * Every upload a person makes — for a flow node, a campaign, a product, or as an
 * attachment in a chat — shows up in the gallery without anyone putting it
 * there. The row only **points** at the file: no bytes are copied, and none of
 * it counts against the storage quota (see AssetOrigin).
 *
 * ⚠️ Everything here is best-effort and swallows its own failures. It runs
 * after the thing the person actually asked for — a flow saved, a message sent
 * — has already happened, and a gallery that cannot index a file is a missing
 * tile, whereas an upload refused because of the gallery is a flow that cannot
 * be saved. That was the one rule agreed before any of this was built: the
 * gallery never makes the original action fail.
 */
class GalleryLibrary
{
    /**
     * Register a file just written by PublishedUpload.
     *
     * @param  array{path: string, filename: string, size: int|false, mime_type: string|null}  $stored
     * @param  string  $localPath  the uploaded temp file, to hash without a round trip to the disk
     */
    public function registerUpload(Tenant $tenant, array $stored, AssetOrigin $origin, string $localPath, ?User $uploader = null): ?GalleryAsset
    {
        try {
            $checksum = hash_file('sha256', $localPath);

            if ($checksum === false) {
                return null;
            }

            return $this->link($tenant, $origin, [
                'path' => $stored['path'],
                'filename' => $stored['filename'],
                'size' => (int) ($stored['size'] ?: 0),
                'mime_type' => $stored['mime_type'],
                'checksum' => $checksum,
                'uploader_id' => $uploader?->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('GalleryLibrary: could not register an upload', [
                'tenant_id' => $tenant->id,
                'path' => $stored['path'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Register a file already on the published disk — the backfill's way in,
     * for uploads made before the gallery listed them. Reads the file once to
     * hash it. Unlike the live paths this one reports failure by throwing: the
     * command running it wants to count what it could not do.
     */
    public function registerPublished(Tenant $tenant, string $path, AssetOrigin $origin): ?GalleryAsset
    {
        $disk = MediaStorage::published();
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return $this->link($tenant, $origin, [
            'path' => $path,
            'filename' => basename($path),
            'size' => (int) rescue(fn () => $disk->size($path), 0, false),
            'mime_type' => rescue(fn () => $disk->mimeType($path), null, false),
            'checksum' => hash_final($context),
            'uploader_id' => null,
        ]);
    }

    /**
     * Whether a message carries a file an agent sent, which is the only kind of
     * message media the gallery lists.
     *
     * Not customer media — agreed explicitly: it arrives unbidden, is often
     * private, and is the volume retention exists to bound. Not flow, AI or
     * campaign sends either: those went out with a file that is already in the
     * gallery under its own origin.
     */
    public static function isAgentAttachment(Message $message): bool
    {
        return $message->sender_type === SenderType::Outgoing
            && $message->sent_by_user_id !== null
            && is_string($message->attachment)
            && $message->attachment !== ''
            && ! MediaRetention::isExternal($message->attachment);
    }

    /** Register an attachment an agent sent. Reads the file once to hash it. */
    public function registerMessageAttachment(Message $message): ?GalleryAsset
    {
        if (! self::isAgentAttachment($message)) {
            return null;
        }

        // Conversations carry no tenant of their own; the connection does.
        $tenantId = $message->conversation?->connection?->tenant_id;

        if ($tenantId === null || MediaRetention::isExpired($message)) {
            return null;
        }

        try {
            $disk = MediaStorage::disk();
            $stream = $disk->readStream($message->attachment);

            if (! is_resource($stream)) {
                return null;
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            $filename = $message->meta['filename'] ?? null;

            return $this->link(Tenant::find($tenantId), AssetOrigin::Message, [
                'path' => $message->attachment,
                'filename' => is_string($filename) && $filename !== '' ? basename($filename) : basename($message->attachment),
                'size' => (int) ($message->attachment_size ?? rescue(fn () => $disk->size($message->attachment), 0, false)),
                'mime_type' => rescue(fn () => $disk->mimeType($message->attachment), null, false),
                'checksum' => hash_final($context),
                'uploader_id' => $message->sent_by_user_id,
                'message_id' => $message->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('GalleryLibrary: could not register a message attachment', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Drop the rows of messages whose media is gone. Rows only — the bytes are
     * the purge's, not ours.
     *
     * @param  array<int, int>  $messageIds
     */
    public function forgetMessages(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        rescue(fn () => GalleryAsset::whereIn('message_id', $messageIds)->delete(), null, false);
    }

    /**
     * @param  array{path: string, filename: string, size: int, mime_type: string|null, checksum: string, uploader_id: int|null, message_id?: int}  $file
     */
    private function link(?Tenant $tenant, AssetOrigin $origin, array $file): ?GalleryAsset
    {
        if ($tenant === null) {
            return null;
        }

        $atPath = GalleryAsset::forTenant($tenant->id)->where('path', $file['path'])->first();

        // Identical bytes are listed once. A library copy always wins — it is
        // the one the workspace pays to keep — and otherwise whichever use
        // arrived first stays: the same product photo attached to three flows
        // is one file, not three tiles.
        $twin = GalleryAsset::forTenant($tenant->id)
            ->where('checksum', $file['checksum'])
            ->when($atPath, fn ($q) => $q->whereKeyNot($atPath->getKey()))
            ->exists();

        if ($twin) {
            // A `replace` that now holds bytes listed elsewhere: the old row
            // describes content that no longer exists at this path.
            $atPath?->delete();

            return null;
        }

        $extension = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        $mime = $file['mime_type'] ?: 'application/octet-stream';

        $attributes = [
            'mime_type' => Str::limit($mime, 150, ''),
            'type' => AssetType::classify($mime, $extension),
            'size_bytes' => max(0, $file['size']),
            'checksum' => $file['checksum'],
        ];

        if ($atPath !== null) {
            // A `replace` upload: same address, new bytes. Its origin and name
            // stay — the file is still the one the person knows.
            $atPath->update($attributes);

            return $atPath;
        }

        return GalleryAsset::create($attributes + [
            'tenant_id' => $tenant->id,
            'origin' => $origin,
            'message_id' => $file['message_id'] ?? null,
            'uuid' => (string) Str::uuid(),
            'public_filename' => Str::limit($file['filename'], 175, ''),
            'uploaded_by_user_id' => $file['uploader_id'],
            'name' => Str::limit($file['filename'], 250, ''),
            'path' => $file['path'],
        ]);
    }
}
