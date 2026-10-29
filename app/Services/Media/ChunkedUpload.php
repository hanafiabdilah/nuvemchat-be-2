<?php

namespace App\Services\Media;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * A large file arriving from the browser in pieces.
 *
 * Exists because of one number: production PHP takes at most 64 MB per
 * request (`upload_max_filesize`), and a file over that is refused by PHP
 * before Laravel sees it — the request arrives with no file, and the person
 * reads "file required" about the file they just picked. Raising the limit
 * moves the problem (proxy limits, memory, timeouts on a phone connection);
 * sending pieces small enough for every one of those removes it.
 *
 * The pieces are appended to one file under storage/app, which every PHP
 * container shares (the `storage` volume) — so two pieces of one file may land
 * on different containers. Offsets are checked against the file itself: a
 * piece that arrives twice (a retried request whose answer was lost) is
 * acknowledged without being written again, and a piece that arrives early is
 * answered with the index the server expects, so the browser can resume.
 *
 * Used by the YouTube and TikTok composers; each says where the assembled file
 * goes (a private disk for YouTube, which receives bytes; the published disk
 * for TikTok, which fetches from a URL).
 */
final class ChunkedUpload
{
    /** Each piece but the last is exactly this big; the browser uses the same number. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    /** A half-sent file older than this is abandoned and swept. */
    public const STALE_HOURS = 24;

    /**
     * @param  string  $scope  Keeps one feature's half-sent files apart from another's.
     * @param  list<string>  $extensions  Accepted extensions, by the name the browser gave.
     * @param  string  $mimePrefix  What the assembled content must sniff as (`video/`).
     */
    public function __construct(
        private readonly string $scope,
        private readonly array $extensions,
        private readonly int $maxBytes,
        private readonly FilesystemAdapter $disk,
        private readonly string $mimePrefix = 'video/',
    ) {}

    /**
     * Take one piece.
     *
     * @return array{done: bool, next_index?: int, file?: array{path: string, name: string, size: int, mime: ?string}}
     */
    public function receive(int $tenantId, string $directory, string $uploadId, int $index, int $total, int $size, string $name, UploadedFile $chunk): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, $this->extensions, true)) {
            throw ValidationException::withMessages(['name' => 'Choose a video file ('.strtoupper(implode(', ', array_slice($this->extensions, 0, 3))).'…).']);
        }

        if ($size > $this->maxBytes) {
            throw ValidationException::withMessages(['size' => 'This file is larger than the '.intdiv($this->maxBytes, 1024 * 1024).' MB this page accepts.']);
        }

        if ($total !== (int) ceil($size / self::CHUNK_BYTES) || $index >= $total) {
            throw ValidationException::withMessages(['index' => 'The pieces of this file do not add up. Start the upload again.']);
        }

        $part = $this->partPath($tenantId, $uploadId);
        $doneKey = "chunked-upload:done:{$this->scope}:{$tenantId}:{$uploadId}";

        return Cache::lock('chunked-upload:'.sha1($part), 60)->block(30, function () use ($part, $doneKey, $index, $total, $size, $chunk, $directory, $name, $extension) {
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
                throw ValidationException::withMessages(['chunk' => 'A piece of the file arrived incomplete. Try again.']);
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

                throw ValidationException::withMessages(['size' => 'The file arrived with the wrong size. Upload it again.']);
            }

            $file = $this->finish($part, $directory, $name, $extension);
            Cache::put($doneKey, $file, now()->addHour());

            return ['done' => true, 'file' => $file];
        });
    }

    /**
     * The assembled file becomes a stored one — once it is proven to be what
     * it claims. Judged by its content, not its name: the extension check
     * above only says what the browser called it.
     */
    private function finish(string $part, string $directory, string $name, string $extension): array
    {
        $mime = mime_content_type($part) ?: null;

        if (! $mime || ! str_starts_with($mime, $this->mimePrefix)) {
            @unlink($part);

            throw ValidationException::withMessages(['name' => 'This file is not a video that can be posted.']);
        }

        $path = MediaFilename::path($directory, MediaFilename::token(), $name, $extension);

        $this->disk->putFileAs(dirname($path), new File($part), basename($path));
        @unlink($part);

        return ['path' => $path, 'name' => basename($path), 'size' => (int) $this->disk->size($path), 'mime' => $mime];
    }

    /** Half-sent files nobody came back for, across every feature. */
    public static function sweepStale(): int
    {
        $removed = 0;

        foreach (glob(self::root().'/*/*/*.part') ?: [] as $file) {
            if (filemtime($file) < now()->subHours(self::STALE_HOURS)->getTimestamp()) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    private function partPath(int $tenantId, string $uploadId): string
    {
        return self::root().'/'.$this->scope.'/'.$tenantId.'/'.$uploadId.'.part';
    }

    private static function root(): string
    {
        return storage_path('app/upload-chunks');
    }
}
