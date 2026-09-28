<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;

/**
 * Media for flows, carousel cards and campaigns: the file behind
 * `POST /api/uploads`, and behind the MCP upload tools.
 *
 * One class for both doors on purpose. The rules for what may be written here —
 * the content-based allow-list, the size, the customer's own filename, the
 * disk whose URLs never expire — are the whole safety of this folder, and a
 * second copy of them would drift on the next change to either.
 */
final class PublishedUpload
{
    /** Per-file ceiling, in kilobytes. */
    public const MAX_KB = 10240;

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
     * @return array{url: string, path: string, filename: string, size: int|false, mime_type: string|null}
     */
    public static function store(UploadedFile $file): array
    {
        $path = 'uploads/'.MediaFilename::build(
            $file->getClientOriginalName(),
            UploadPolicy::storedExtension($file),
        );

        MediaStorage::published()->putFileAs(dirname($path), $file, basename($path));

        return [
            'url' => MediaStorage::publishedUrl($path),
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }
}
