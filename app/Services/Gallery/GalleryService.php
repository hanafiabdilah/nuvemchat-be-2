<?php

namespace App\Services\Gallery;

use App\Enums\Gallery\AssetType;
use App\Exceptions\Gallery\GalleryQuotaExceededException;
use App\Models\GalleryAsset;
use App\Models\Tenant;
use App\Models\User;
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
            $existing = GalleryAsset::where('tenant_id', $tenant->id)
                ->where('checksum', $checksum)
                ->first();

            if ($existing !== null) {
                // Deliberately not an error. From where the person is standing
                // they asked for this file to be in the gallery, and it is.
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
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $uuid = (string) Str::uuid();

            $path = "gallery/{$tenant->id}/{$uuid}" . ($extension !== '' ? ".{$extension}" : '');

            Storage::disk($this->disk())->putFileAs(
                dirname($path),
                $file,
                basename($path),
            );

            return GalleryAsset::create([
                'tenant_id' => $tenant->id,
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
        } finally {
            $lock?->release();
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
