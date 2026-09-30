<?php

namespace App\Services\Media;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Media\UploadConflict;
use App\Exceptions\Media\FileAlreadyExistsException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gallery\GalleryLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Media for flows, carousel cards and campaigns: the file behind
 * `POST /api/uploads`, and behind the MCP upload tools.
 *
 * One class for both doors on purpose. The rules for what may be written here —
 * the content-based allow-list, the size, the customer's own filename, the
 * disk whose URLs never expire — are the whole safety of this folder, and a
 * second copy of them would drift on the next change to either.
 *
 * **One folder per workspace** (`uploads/{tenant}`), which is what makes the
 * bare filename usable as the address: `uploads/3/Contrato de Serviço.pdf`. The
 * folder is the isolation, so no code has to be smuggled into the name to keep
 * two businesses apart.
 *
 * ⚠️ The consequence is that a repeated name inside one workspace is now a
 * genuine collision, and it is answered rather than guessed — see
 * UploadConflict. Guessing either way is the bug: silently replacing repoints
 * every flow node already pointing at that URL, and silently renaming leaves
 * someone hunting for the file they thought they had just updated.
 */
final class PublishedUpload
{
    /** Per-file ceiling, in kilobytes. */
    public const MAX_KB = 10240;

    /** How far ` (2)`, ` (3)`, … is tried before falling back to a short code. */
    private const MAX_NUMBERED = 999;

    /** Validation rules for the `file` field. See UploadPolicy. */
    public static function rules(): array
    {
        return UploadPolicy::rules(self::MAX_KB);
    }

    /**
     * Store an already-validated file and describe it.
     *
     * ⚠️ The stored name is the customer's name for this file, not ours — see
     * MediaFilename. And the extension comes from the content, never from the
     * name the browser sent: it decides the Content-Type served back off our
     * own domain, and the MIME type announced to the channel.
     *
     * @return array{url: string, path: string, filename: string, size: int|false, mime_type: string|null, replaced: bool, renamed: bool}
     *
     * `$origin` lists the file in the workspace's gallery as well — a row that
     * points at it, free of the storage quota (see GalleryLibrary). Best-effort:
     * a failure to list it never fails the upload.
     *
     * @throws FileAlreadyExistsException when the name is taken and the caller said `cancel`
     */
    public static function store(
        UploadedFile $file,
        Tenant $tenant,
        UploadConflict $onConflict = UploadConflict::Cancel,
        ?AssetOrigin $origin = null,
        ?User $uploader = null,
    ): array {
        $folder = 'uploads/'.$tenant->id;
        $name = MediaFilename::name(
            $file->getClientOriginalName(),
            UploadPolicy::storedExtension($file),
        );

        // ⚠️ Locked for the same reason the gallery locks: two uploads of one
        // name arriving together would both find `(2)` free and both write it,
        // and losing a file to a race is the exact failure this whole scheme
        // exists to prevent. Waiting rather than failing — a person dropping
        // twenty files at once is one intent.
        $lock = Cache::lock("uploads:store:{$tenant->id}", 15);
        $held = false;

        try {
            $held = (bool) rescue(fn () => $lock->block(10), false, false);

            if (! $held) {
                Log::warning('PublishedUpload: could not acquire the upload lock, storing without it', [
                    'tenant_id' => $tenant->id,
                ]);
            }

            $stored = self::write($file, $folder, $name, $onConflict);

            if ($origin !== null) {
                app(GalleryLibrary::class)->registerUpload($tenant, $stored, $origin, $file->getRealPath(), $uploader);
            }

            return $stored;
        } finally {
            if ($held) {
                $lock->release();
            }
        }
    }

    /**
     * @return array{url: string, path: string, filename: string, size: int|false, mime_type: string|null, replaced: bool, renamed: bool}
     *
     * @throws FileAlreadyExistsException
     */
    private static function write(
        UploadedFile $file,
        string $folder,
        string $name,
        UploadConflict $onConflict,
    ): array {
        $disk = MediaStorage::published();
        $taken = $disk->exists("{$folder}/{$name}");

        if ($taken && $onConflict === UploadConflict::Cancel) {
            throw new FileAlreadyExistsException($name, self::describeExisting("{$folder}/{$name}"));
        }

        $stored = $taken && $onConflict === UploadConflict::Rename
            ? self::freeName($folder, $name)
            : $name;

        $path = "{$folder}/{$stored}";

        $disk->putFileAs($folder, $file, $stored);

        return [
            'url' => MediaStorage::publishedUrl($path),
            'path' => $path,
            'filename' => $stored,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'replaced' => $taken && $onConflict === UploadConflict::Replace,
            'renamed' => $stored !== $name,
        ];
    }

    /**
     * `catalogo.pdf` → `catalogo (2).pdf`, the numbering every file manager
     * uses, which is now readable because the name keeps its spaces.
     */
    private static function freeName(string $folder, string $name): string
    {
        $disk = MediaStorage::published();
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? ".{$extension}" : '';

        for ($n = 2; $n <= self::MAX_NUMBERED; $n++) {
            $candidate = "{$base} ({$n}){$suffix}";

            if (! $disk->exists("{$folder}/{$candidate}")) {
                return $candidate;
            }
        }

        // A workspace with 999 files of one name is not a case worth failing
        // for, and a caller that asked to keep both must get both.
        return "{$base} (".MediaFilename::token().")".$suffix;
    }

    /**
     * @return array{url: string, path: string, size: int|null, modified_at: string|null}
     */
    private static function describeExisting(string $path): array
    {
        $disk = MediaStorage::published();

        return [
            'url' => MediaStorage::publishedUrl($path),
            'path' => $path,
            // rescue(): the answer to "what is already there" must not itself
            // fail the request, and on object storage these are round trips.
            'size' => rescue(fn () => $disk->size($path), null, false),
            'modified_at' => rescue(
                fn () => \Carbon\Carbon::createFromTimestamp($disk->lastModified($path))->toIso8601String(),
                null,
                false,
            ),
        ];
    }
}
