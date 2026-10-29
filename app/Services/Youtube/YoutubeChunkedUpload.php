<?php

namespace App\Services\Youtube;

use App\Services\Media\ChunkedUpload;
use App\Services\Media\MediaStorage;
use Illuminate\Http\UploadedFile;

/**
 * A YouTube video arriving from the browser in pieces (see ChunkedUpload).
 *
 * Assembled onto the private media disk: YouTube receives bytes from us, so
 * nothing about the file needs a public address.
 */
class YoutubeChunkedUpload
{
    /** Each piece but the last is exactly this big; the browser uses the same number. */
    public const CHUNK_BYTES = ChunkedUpload::CHUNK_BYTES;

    /** Video containers YouTube accepts, by extension. */
    public const EXTENSIONS = ['mp4', 'mov', 'm4v', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'mpeg', 'mpg', '3gp'];

    /**
     * @return array{done: bool, next_index?: int, file?: array{path: string, name: string, size: int, mime: ?string}}
     */
    public function receive(int $tenantId, string $uploadId, int $index, int $total, int $size, string $name, UploadedFile $chunk): array
    {
        return (new ChunkedUpload(
            'youtube',
            self::EXTENSIONS,
            (int) config('youtube.max_upload_mb', 2048) * 1024 * 1024,
            MediaStorage::disk(),
        ))->receive($tenantId, 'youtube/'.$tenantId, $uploadId, $index, $total, $size, $name, $chunk);
    }
}
