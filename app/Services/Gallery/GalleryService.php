<?php

namespace App\Services\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\AssetType;
use App\Exceptions\Gallery\GalleryQuotaExceededException;
use App\Models\GalleryAsset;
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
    ) {}

    /**
     * Store an uploaded file in the tenant's library.
     *
     * @param  string|null  $name  display name; falls back to the client filename
     *
     * @throws GalleryQuotaExceededException when the library has no room for it
     */
    public function store(Tenant $tenant, UploadedFile $file, ?User $uploader = null, ?string $name = null): GalleryAsset
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
                ->counted()
                ->where('checksum', $checksum)
                ->first();

            if ($existing !== null) {
                // Deliberately not an error. From where the person is standing
                // they asked for this file to be in the gallery, and it is.
                $this->supersedeLinkedTwins($existing);

                return $existing;
            }

            if (! $this->storage->canStore($tenant, $size)) {
                throw new GalleryQuotaExceededException(
                    $this->storage->usedBytes($tenant),
                    $this->storage->limitBytes($tenant),
                    $size,
                );
            }

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
     * Only meaningful for `message` rows — they are the only ones that expire.
     * The copy counts against the quota like any upload, and is refused the
     * same way when there is no room; the linked row is then replaced by the
     * library one (see supersedeLinkedTwins), so the tile stays where it was
     * and stops being temporary.
     *
     * @throws GalleryQuotaExceededException
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

            return $this->store($asset->tenant, $file, $by, $asset->name);
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
     * Remove the file and its row.
     *
     * A hard delete, and the bytes go with it: the whole point of the meter is
     * that deleting frees space, and a soft delete that kept the file would
     * charge the customer for something the product told them was gone.
     *
     * ⚠️ Messages already sent with this asset point at its URL and will lose
     * their picture. That is inherent to a library whose files are referenced
     * rather than copied — the alternative is duplicating every send, which is
     * the cost this feature exists to remove — so the confirmation dialog says
     * so before the click.
     */
    public function delete(GalleryAsset $asset): void
    {
        // ⚠️ A linked row loses its tile and nothing else. The file belongs to
        // the flow node, campaign, product or message that uploaded it, and
        // deleting it from here would break that — silently, somewhere else.
        if ($asset->isLinked()) {
            $asset->delete();

            return;
        }

        $path = $asset->path;

        $asset->delete();

        try {
            Storage::disk($this->disk())->delete($path);
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
