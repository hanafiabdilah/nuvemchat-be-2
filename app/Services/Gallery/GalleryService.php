<?php

namespace App\Services\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\AssetType;
use App\Enums\Message\AttachmentStatus;
use App\Events\MessageUpdated;
use App\Exceptions\Media\FileInUseException;
use App\Exceptions\Media\StorageQuotaExceededException;
use App\Models\GalleryAsset;
use App\Models\Message;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\UploadPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Putting a file into a workspace's library, and taking it out again.
 *
 * The two rules that shape everything here:
 *
 *  1. Identical bytes are stored once. A workspace that uploads the same
 *     catalogue twice has one file and pays for it once — the second upload
 *     returns the row the first one made. That is not a nicety: without it the
 *     obvious way to use a gallery (drag the folder in again next month)
 *     quietly doubles the bill.
 *
 *  2. Nothing is written until the space is confirmed to exist, and the
 *     confirmation happens under a lock. Two uploads started at the same second
 *     could otherwise both pass a check neither could pass alone — the same
 *     reason CreditService checks the balance inside the row lock it writes in.
 */
class GalleryService
{
    public function __construct(
        private readonly GalleryStorage $storage,
        private readonly GalleryAssetUsage $usage,
    ) {}

    /**
     * Store an uploaded file in the tenant's library.
     *
     * @param  string|null  $name  display name; falls back to the client filename
     * @param  int|null  $folderId  where to file it; the caller has already checked
     *                              it is this workspace's. Ignored when the bytes
     *                              are already in the library: that file stays
     *                              where somebody filed it.
     *
     * @throws StorageQuotaExceededException when the workspace has no room for it
     */
    public function store(Tenant $tenant, UploadedFile $file, ?User $uploader = null, ?string $name = null, ?int $folderId = null): GalleryAsset
    {
        $size = (int) $file->getSize();
        $checksum = hash_file('sha256', $file->getRealPath());

        $lock = Cache::lock("gallery:store:{$tenant->id}", 15);

        try {
            // Waiting rather than failing: a person dropping twenty files at
            // once is one intent, and refusing half of them because they raced
            // each other would be this lock leaking into the product.
            $lock->block(10);
        } catch (\Throwable $e) {
            Log::warning('GalleryService: could not acquire the upload lock, storing without it', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            $lock = null;
        }

        try {
            // Against library files only. The same bytes listed as a flow
            // upload or a sent attachment are not "already in the gallery" in
            // the sense the person means — they are not kept, and a `message`
            // row will disappear with the message's media.
            $existing = GalleryAsset::where('tenant_id', $tenant->id)
                ->kept()
                ->where('checksum', $checksum)
                ->first();

            if ($existing !== null) {
                // Deliberately not an error. From where the person is standing
                // they asked for this file to be in the gallery, and it is.
                $this->supersedeLinkedTwins($existing);

                return $existing;
            }

            // Linked rows holding these same bytes stop being listed once the
            // library has them (supersedeLinkedTwins), and since every row
            // counts, their space comes back as this one takes it. That is what
            // lets "save to gallery" work in a workspace that is exactly full:
            // the meter does not grow, so there is nothing to refuse.
            $twinBytes = (int) GalleryAsset::where('tenant_id', $tenant->id)
                ->where('checksum', $checksum)
                ->where('origin', '!=', AssetOrigin::Gallery->value)
                ->sum('size_bytes');

            $this->storage->assertCanStore($tenant, $size - $twinBytes);

            $original = $file->getClientOriginalName() ?: 'arquivo';

            // ⚠️ Derived from the bytes, not from the name the browser sent.
            // This extension is written into the storage path, signed into the
            // public filename, and read back by OutboundMedia as the MIME type
            // to announce — so taking the uploader's word for it let a file
            // claim to be something it is not, all the way out to the channel.
            // See UploadPolicy::storedExtension().
            $extension = UploadPolicy::storedExtension($file);
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $uuid = (string) Str::uuid();

            $path = "gallery/{$tenant->id}/{$uuid}".($extension !== '' ? ".{$extension}" : '');

            Storage::disk($this->disk())->putFileAs(
                dirname($path),
                $file,
                basename($path),
            );

            $asset = GalleryAsset::create([
                'tenant_id' => $tenant->id,
                'origin' => AssetOrigin::Gallery,
                'folder_id' => $folderId,
                'uuid' => $uuid,
                'public_filename' => $this->publicFilename($original, $extension),
                'uploaded_by_user_id' => $uploader?->id,
                'name' => Str::limit(trim($name ?: $original), 250, ''),
                'path' => $path,
                'mime_type' => Str::limit($mime, 150, ''),
                'type' => AssetType::classify($mime, $extension),
                'size_bytes' => $size,
                'checksum' => $checksum,
                'meta' => array_filter(['original_filename' => $original]),
            ]);

            $this->supersedeLinkedTwins($asset);

            return $asset;
        } finally {
            $lock?->release();
        }
    }

    /**
     * "Salvar na galeria": copy a linked file into the library so it is kept.
     *
     * Only meaningful for temporary rows — `message` and `ai`, the ones a
     * retention deletes.
     * The linked row is replaced by the library one (see supersedeLinkedTwins),
     * so the tile stays where it was and stops being temporary — and since the
     * linked row was already counted, the meter does not move.
     *
     * @throws StorageQuotaExceededException
     */
    public function keep(GalleryAsset $asset, ?User $by = null): GalleryAsset
    {
        if (! $asset->isLinked()) {
            return $asset;
        }

        $disk = $asset->origin === AssetOrigin::Message ? MediaStorage::disk() : MediaStorage::published();
        $stream = $disk->readStream($asset->path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('The file behind this gallery row is gone.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'gallery-keep-');

        try {
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            $file = new UploadedFile($tmp, $asset->public_filename, $asset->mime_type, null, true);

            // Kept where it was filed: the tile changes from temporary to kept
            // in the same folder, rather than jumping to the top level.
            return $this->store($asset->tenant, $file, $by, $asset->name, $asset->folder_id);
        } finally {
            @unlink($tmp);
        }
    }

    /** Rename the asset. Only the label moves — see below. */
    public function rename(GalleryAsset $asset, string $name): GalleryAsset
    {
        // `public_filename` is deliberately untouched. It is signed into every
        // URL already handed to WhatsApp, to Meta's fetchers and to every
        // message bubble ever sent with this file; changing it would invalidate
        // the signature and break all of them to fix a caption nobody outside
        // this dashboard reads.
        $asset->update(['name' => Str::limit(trim($name), 250, '')]);

        return $asset->fresh();
    }

    /**
     * Remove the file and its row — the bytes always go with it.
     *
     * A hard delete, because the whole point of the meter is that deleting
     * frees space. Since every file counts (Nov 2026), that now includes files
     * that came from a flow, a campaign, the catalog, the API or the AI: hiding
     * their tile and keeping the bytes would leave a meter nobody could bring
     * down, or a free shelf for anyone who noticed.
     *
     * ⚠️ Refused while a flow, a live campaign or a product still sends the
     * file (GalleryAssetUsage) — deleting it would break that send somewhere
     * else, weeks later, with nothing on screen to say why.
     *
     * ⚠️ Messages already sent with the file point at its URL and lose it. That
     * is inherent to files that are referenced rather than copied, so the
     * confirmation dialog says so before the click.
     *
     * @throws FileInUseException
     */
    public function delete(GalleryAsset $asset): void
    {
        if ($asset->origin === AssetOrigin::Message) {
            $this->deleteMessageFile($asset);

            return;
        }

        $usedIn = $this->usage->find($asset);

        if ($usedIn !== []) {
            throw new FileInUseException($usedIn);
        }

        $path = $asset->path;
        $disk = $asset->origin->isPublished() ? MediaStorage::published() : Storage::disk($this->disk());

        $asset->delete();

        if ($asset->origin === AssetOrigin::Ai) {
            app(GalleryLibrary::class)->forgetGenerated($path);
        }

        try {
            $disk->delete($path);
        } catch (\Throwable $e) {
            // The row is gone, so the space is already free as far as the meter
            // is concerned. An orphaned file on disk is a cleanup problem, not
            // a reason to fail a delete the customer asked for.
            Log::warning('GalleryService: could not delete a gallery file from disk', [
                'gallery_asset_id' => $asset->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete a file an agent (or the AI) sent in a conversation, before its
     * retention would.
     *
     * The message keeps its place in the thread, the same way the retention
     * purge leaves it: its attachment is cleared and marked expired, so the
     * bubble says the media is gone instead of pointing at a missing file.
     *
     * ⚠️ Saved without model events, then broadcast by hand. MessageGalleryObserver
     * answers a cleared attachment by forgetting *every* gallery row of the
     * message — and an e-mail carries several, so deleting one attachment would
     * silently drop the others from the meter while their bytes stayed. Unlike
     * the purge, `updated_at` does move: this is a change the open dashboards
     * should pick up, and the delta sync reads that column.
     */
    private function deleteMessageFile(GalleryAsset $asset): void
    {
        $message = $asset->message;
        $path = $asset->path;

        $asset->delete();

        try {
            MediaStorage::disk()->delete($path);
        } catch (\Throwable $e) {
            Log::warning('GalleryService: could not delete a sent attachment from disk', [
                'gallery_asset_id' => $asset->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        if ($message === null) {
            return;
        }

        $changed = false;

        if ($message->attachment === $path) {
            $message->forceFill([
                'attachment' => null,
                'attachment_size' => null,
                'attachment_status' => AttachmentStatus::Expired,
            ]);
            $changed = true;
        }

        $meta = $message->meta;

        if (is_array($meta['email']['attachments'] ?? null)) {
            foreach ($meta['email']['attachments'] as $index => $attachment) {
                if (($attachment['path'] ?? null) === $path) {
                    unset($meta['email']['attachments'][$index]['path']);
                    $meta['email']['attachments'][$index]['expired'] = true;
                    $changed = true;
                }
            }

            $message->forceFill(['meta' => $meta]);
        }

        if (! $changed) {
            return;
        }

        Message::withoutEvents(fn () => $message->save());

        rescue(
            fn () => broadcast(new MessageUpdated($message->load('reactions.contact', 'repliedMessage'))),
            null,
            false,
        );
    }

    /**
     * Record that the asset was just sent.
     *
     * Written straight through the query builder so `updated_at` stays put:
     * "last used" is not an edit to the asset, and the gallery list is sorted
     * by when files were added.
     */
    public function markUsed(GalleryAsset $asset): void
    {
        GalleryAsset::whereKey($asset->getKey())->toBase()->update(['last_used_at' => now()]);
    }

    /**
     * Once a file is in the library, its linked twins stop being listed.
     *
     * Rows only — their bytes belong to whatever uploaded them. A product that
     * pointed at the linked row follows the file into the library, rather than
     * losing its reference to a null-on-delete.
     */
    private function supersedeLinkedTwins(GalleryAsset $asset): void
    {
        rescue(function () use ($asset) {
            $twins = GalleryAsset::where('tenant_id', $asset->tenant_id)
                ->where('checksum', $asset->checksum)
                ->where('origin', '!=', AssetOrigin::Gallery->value)
                ->pluck('id');

            if ($twins->isEmpty()) {
                return;
            }

            Product::whereIn('gallery_asset_id', $twins)->update(['gallery_asset_id' => $asset->id]);
            GalleryAsset::whereIn('id', $twins)->delete();
        }, null, false);
    }

    /**
     * The last segment of the public URL: a slug of the original name plus its
     * real extension.
     *
     * The extension has to survive, because it is the only thing telling
     * OutboundMedia what MIME type to send and WhatsApp what to call the file.
     * The rest is cosmetic, so it is slugged down to characters that cannot
     * change the meaning of a path.
     */
    private function publicFilename(string $original, string $extension): string
    {
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME) ?: 'arquivo');
        $base = $base !== '' ? Str::limit($base, 150, '') : 'arquivo';

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }

    private function disk(): string
    {
        return (string) config('gallery.disk', 'local');
    }
}
