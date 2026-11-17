<?php

namespace App\Services\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\AssetType;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\SenderType;
use App\Models\AiMediaGeneration;
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
 * there, and so does what the AI makes (voice replies, the AI media node). The
 * row only **points** at the file: no bytes are copied.
 *
 * ⚠️ Since Nov 2026 every row counts against the storage quota (see
 * AssetOrigin), so this class is also what keeps the meter true. Registering
 * still never refuses anything: the refusal happens before the upload, at the
 * door (GalleryStorage::assertCanStore), and AI output is never refused at all.
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
     * Whether a message carries a file the workspace sent on its own account —
     * an agent's attachment, or the AI's voice reply — which is the only kind
     * of message media the gallery lists (and the quota counts).
     *
     * Not customer media — agreed explicitly: it arrives unbidden, can never be
     * refused, and is the volume retention exists to bound. Not plain flow or
     * campaign sends either: those went out with a file that is already in the
     * gallery under its own origin.
     */
    public static function isWorkspaceAttachment(Message $message): bool
    {
        return $message->sender_type === SenderType::Outgoing
            && ($message->sent_by_user_id !== null || $message->sent_by_ai_hub_agent_id !== null)
            && is_string($message->attachment)
            && $message->attachment !== ''
            && ! MediaRetention::isExternal($message->attachment);
    }

    /**
     * Register what an agent or the AI sent. Reads each file once to hash it.
     *
     * An e-mail carries one entry per attachment in meta and mirrors only the
     * first into `attachment`, so the rest are registered from meta — each
     * stored file is space the workspace uses, and listing only the first
     * would let the other nineteen ride for free.
     */
    public function registerMessageAttachment(Message $message): ?GalleryAsset
    {
        if (! self::isWorkspaceAttachment($message)) {
            return null;
        }

        // Conversations carry no tenant of their own; the connection does.
        $tenantId = $message->conversation?->connection?->tenant_id;

        if ($tenantId === null || MediaRetention::isExpired($message)) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        $filename = $message->meta['filename'] ?? null;

        $first = $this->registerMessageFile($tenant, $message, $message->attachment, is_string($filename) && $filename !== '' ? basename($filename) : null, $message->attachment_size);

        foreach ((array) ($message->meta['email']['attachments'] ?? []) as $attachment) {
            $path = $attachment['path'] ?? null;

            if (! is_string($path) || $path === '' || $path === $message->attachment || ! empty($attachment['expired'])) {
                continue;
            }

            $name = is_string($attachment['name'] ?? null) && $attachment['name'] !== '' ? basename($attachment['name']) : null;

            $this->registerMessageFile($tenant, $message, $path, $name, null);
        }

        return $first;
    }

    /**
     * Register a file the AI media node made (`uploads/{tenant}/ai-media/…`).
     *
     * Counted like every other file, deleted after `media.retention.ai_days`
     * (media:purge), and — unlike everything a person adds — never checked
     * against the quota before it is written: a flow taking its
     * failure branch, or a customer not getting the picture they were promised,
     * because of a storage meter is the worse outcome. The meter may read over
     * 100% afterwards; the next upload a person tries is what gets refused.
     */
    public function registerGenerated(Tenant $tenant, string $path, string $bytes, ?string $mimeType, ?string $name = null): ?GalleryAsset
    {
        try {
            return $this->link($tenant, AssetOrigin::Ai, [
                'path' => $path,
                // Every generation is stored as `image.png` in its own folder,
                // so the folder name alone would list a gallery of identical
                // names; the caller says which generation it was.
                'filename' => $name ?? basename($path),
                'size' => strlen($bytes),
                'mime_type' => $mimeType,
                'checksum' => hash('sha256', $bytes),
                'uploader_id' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('GalleryLibrary: could not register an AI-generated file', [
                'tenant_id' => $tenant->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * What has to happen once an AI media file is gone — by retention
     * (media:purge) or because someone deleted it from the gallery.
     *
     * The generation stops claiming a file (FlowExecutor reads a null path as
     * "not made"), and every message that sent it is marked expired. Those
     * messages hold the file's absolute URL, which carries no expiry a client
     * could read, so they are written with `updated_at` moved — otherwise the
     * bubbles would stay broken images until a resync. Rows only: the caller
     * deletes the bytes and the gallery row.
     */
    public function forgetGenerated(string $path): void
    {
        rescue(function () use ($path) {
            $generations = AiMediaGeneration::where('path', $path)->get(['id', 'conversation_id']);

            foreach ($generations as $generation) {
                if ($generation->conversation_id !== null) {
                    Message::query()
                        ->where('conversation_id', $generation->conversation_id)
                        ->where('attachment', 'like', '%'.$path)
                        ->update([
                            'attachment' => null,
                            'attachment_size' => null,
                            'attachment_status' => AttachmentStatus::Expired,
                            'updated_at' => now(),
                        ]);
                }

                AiMediaGeneration::whereKey($generation->id)->toBase()->update(['path' => null]);
            }
        }, null, false);
    }

    private function registerMessageFile(?Tenant $tenant, Message $message, string $path, ?string $filename, ?int $knownSize): ?GalleryAsset
    {
        try {
            $disk = MediaStorage::disk();
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return null;
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            return $this->link($tenant, AssetOrigin::Message, [
                'path' => $path,
                'filename' => $filename ?? basename($path),
                'size' => (int) ($knownSize ?? rescue(fn () => $disk->size($path), 0, false)),
                'mime_type' => rescue(fn () => $disk->mimeType($path), null, false),
                'checksum' => hash_final($context),
                'uploader_id' => $message->sent_by_user_id,
                'message_id' => $message->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('GalleryLibrary: could not register a message attachment', [
                'message_id' => $message->id,
                'path' => $path,
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
