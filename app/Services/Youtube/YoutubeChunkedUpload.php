<?php

namespace App\Services\Youtube;

use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * A video arriving from the browser in pieces.
 *
 * Exists because of one number: production PHP takes at most 64 MB per
 * request (`upload_max_filesize`), and a video over that is refused by PHP
 * before Laravel sees it — the request arrives with no file, and the person
 * reads "file required" about the file they just picked. Raising the limit
 * moves the problem (proxy limits, memory, timeouts on a phone connection);
 * sending pieces small enough for every one of those removes it.
 *
 * The pieces are appended to one file under storage/app, which every PHP
 * container shares (the `storage` volume) — so two pieces of one video may
 * land on different containers. Offsets are checked against the file itself:
 * a piece that arrives twice (a retried request whose answer was lost) is
 * acknowledged without being written again, and a piece that arrives early is
 * refused with the index the server expects, so the browser can resume.
 */
class YoutubeChunkedUpload
{
    /** Each piece but the last is exactly this big; the browser uses the same number. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /** Video containers YouTube accepts, by extension. */
    public const EXTENSIONS = ['mp4', 'mov', 'm4v', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'mpeg', 'mpg', '3gp'];

    /** A half-sent video older than this is abandoned and swept. */
    public const STALE_HOURS = 24;

    /**
     * Take one piece.
     *
     * @return array{done: bool, next_index?: int, file?: array{path: string, name: string, size: int, mime: ?string}}
     */
    public function receive(int $tenantId, string $uploadId, int $index, int $total, int $size, string $name, UploadedFile $chunk): array
    {
        $maxBytes = (int) config('youtube.max_upload_mb', 2048) * 1024 * 1024;
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['name' => 'Choose a video file (MP4, MOV, WEBM…).']);
        }

        if ($size > $maxBytes) {
            throw ValidationException::withMessages(['size' => 'This video is larger than the '.config('youtube.max_upload_mb', 2048).' MB this page accepts.']);
        }

        if ($total !== (int) ceil($size / self::CHUNK_BYTES) || $index >= $total) {
            throw ValidationException::withMessages(['index' => 'The pieces of this video do not add up. Start the upload again.']);
        }

        $part = $this->partPath($tenantId, $uploadId);
        $doneKey = "youtube:chunk-done:{$tenantId}:{$uploadId}";

        return Cache::lock('youtube:chunk:'.sha1($part), 60)->block(30, function () use ($part, $doneKey, $index, $total, $size, $chunk, $tenantId, $name, $extension) {
            // The last piece landed already and its answer was lost: answer
            // again with the same file, never assemble a second one.
            if ($finished = Cache::get($doneKey)) {
                return ['done' => true, 'file' => $finished];
            }

            clearstatcache(true, $part);
            $have = is_file($part) ? (int) filesize($part) : 0;
            $offset = $index * self::CHUNK_BYTES;
            $expected = $index === $total - 1 ? $size - $offset : self::CHUNK_BYTES;

            if ((int) $chunk->getSize() !== $expected) {
                throw ValidationException::withMessages(['chunk' => 'A piece of the video arrived incomplete. Try again.']);
            }

            // Not where this piece goes: either it was written already (a
            // retry) or one before it is missing. Either way, say which piece
            // comes next and let the browser carry on from there.
            if ($have !== $offset) {
                return ['done' => false, 'next_index' => intdiv($have, self::CHUNK_BYTES)];
            }

            if (! is_dir(dirname($part))) {
                mkdir(dirname($part), 0775, true);
            }

            $in = fopen($chunk->getRealPath(), 'rb');
            $out = fopen($part, 'ab');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            if ($index < $total - 1) {
                return ['done' => false, 'next_index' => $index + 1];
            }

            clearstatcache(true, $part);

            if ((int) filesize($part) !== $size) {
                @unlink($part);

                throw ValidationException::withMessages(['size' => 'The video arrived with the wrong size. Upload it again.']);
            }

            $file = $this->finish($part, $tenantId, $name, $extension);
            Cache::put($doneKey, $file, now()->addHour());

            return ['done' => true, 'file' => $file];
        });
    }

    /**
     * The assembled file becomes a stored video — once it is proven to be one.
     *
     * Judged by its content, not its name: the extension check above only
     * says what the browser called it.
     */
    private function finish(string $part, int $tenantId, string $name, string $extension): array
    {
        $mime = mime_content_type($part) ?: null;

        if (! $mime || ! str_starts_with($mime, 'video/')) {
            @unlink($part);

            throw ValidationException::withMessages(['name' => 'This file is not a video YouTube can take.']);
        }

        $path = MediaFilename::path('youtube/'.$tenantId, MediaFilename::token(), $name, $extension);

        MediaStorage::disk()->putFileAs(dirname($path), new File($part), basename($path));
        @unlink($part);

        return ['path' => $path, 'name' => basename($path), 'size' => (int) MediaStorage::disk()->size($path), 'mime' => $mime];
    }

    /** Half-sent videos nobody came back for. */
    public static function sweepStale(): int
    {
        $removed = 0;

        foreach (glob(self::root().'/*/*.part') ?: [] as $file) {
            if (filemtime($file) < now()->subHours(self::STALE_HOURS)->getTimestamp()) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function partPath(int $tenantId, string $uploadId): string
    {
        return self::root().'/'.$tenantId.'/'.$uploadId.'.part';
    }

    private static function root(): string
    {
        return storage_path('app/youtube-chunks');
    }
}
