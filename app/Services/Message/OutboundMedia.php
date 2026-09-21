<?php

namespace App\Services\Message;

use App\Support\OutboundHttp;
use App\Support\PublicUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Normalizes an outbound media input into either:
 *  - a URL (fast-path: send by URL using the channel's native URL support), or
 *  - an UploadedFile (legacy path: encode/host the raw bytes).
 *
 * Handlers resolve this at the top of each handleSend{Image,Audio,Video,Document}.
 * When a `media_url` is provided, `toUploadedFile()` also backs the download-reupload
 * fallback used when a channel rejects the URL or needs transcoding.
 */
class OutboundMedia
{
    /**
     * The most this server will pull down for one send.
     *
     * Set just above the largest a channel actually accepts (100 MB for an
     * e-mail attachment), so it never refuses a send that would have worked
     * while still bounding what one request can be made to fetch.
     */
    private const MAX_DOWNLOAD_BYTES = 110 * 1024 * 1024;

    private function __construct(
        public readonly ?UploadedFile $file,
        public readonly ?string $url,
        public readonly string $filename,
        public readonly string $extension,
        public readonly ?string $mimeType,
    ) {}

    /**
     * Resolve media input from a handler `$data` array.
     *
     * URL mode:  $data['media_url'] is a string URL.
     * File mode: $data[$fileKey] is an UploadedFile.
     * Returns null when neither is present.
     */
    public static function fromData(array $data, string $fileKey): ?self
    {
        $url = $data['media_url'] ?? null;

        if (is_string($url) && $url !== '') {
            // ⚠️ Checked here, once, rather than in each channel handler's
            // validation rules. Every send endpoint accepts `media_url` with
            // nothing but Laravel's `url` rule, which happily passes
            // `http://169.254.169.254/v1.json`, `http://127.0.0.1:6379` and
            // Docker service names — and the download fallback below hands the
            // bytes to the channel, i.e. to the attacker's own chat. Twenty
            // rule strings would have been twenty chances to miss one; this is
            // the single place all of them funnel through.
            self::assertFetchable($url);

            $pathFromUrl = parse_url($url, PHP_URL_PATH) ?: '';
            $basename = $pathFromUrl ? basename($pathFromUrl) : '';
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION) ?: '');
            $mime = $extension ? self::mimeFromExtension($extension) : null;

            if ($basename === '' || $extension === '') {
                $basename = $basename !== '' ? $basename : 'attachment';
            }

            return new self(
                file: null,
                url: $url,
                filename: $basename,
                extension: $extension,
                mimeType: $mime,
            );
        }

        $file = $data[$fileKey] ?? null;
        if ($file instanceof UploadedFile) {
            return self::fromFile($file);
        }

        return null;
    }

    /**
     * Wrap an already-resolved UploadedFile (e.g. produced by the download
     * fallback) as file-mode media.
     */
    public static function fromFile(UploadedFile $file): self
    {
        return new self(
            file: $file,
            url: null,
            filename: $file->getClientOriginalName() ?: 'attachment',
            extension: strtolower($file->getClientOriginalExtension() ?: ''),
            mimeType: $file->getMimeType(),
        );
    }

    public function isUrl(): bool
    {
        return $this->url !== null;
    }

    /**
     * Download the URL into a temporary UploadedFile (fallback path).
     * Returns null on failure. The temp file is flagged as `test` so Laravel
     * treats it as a valid uploaded file and cleans it up with the request.
     */
    public function toUploadedFile(): ?UploadedFile
    {
        if ($this->url === null) {
            return $this->file;
        }

        try {
            // Re-checked rather than trusted from fromData(): this object can
            // also be built by fromFile() and handed a URL later, and the
            // resolution behind a name can change between the two moments.
            self::assertFetchable($this->url);

            $tempPath = tempnam(sys_get_temp_dir(), 'media_');

            // ⚠️ Streamed to disk with a ceiling, not read into a string. The
            // previous `file_put_contents($temp, $response->body())` put the
            // whole response in PHP's memory first, so a `media_url` pointing
            // at something large was an out-of-memory in one request — and
            // pointing at something large is free.
            $response = OutboundHttp::guard(Http::timeout(30), self::MAX_DOWNLOAD_BYTES)
                ->sink($tempPath)
                ->get($this->url);

            if (!$response->successful()) {
                Log::warning('OutboundMedia: download returned non-success', [
                    'url' => $this->url,
                    'status' => $response->status(),
                ]);
                @unlink($tempPath);

                return null;
            }

            // A server that sent no Content-Length is bounded here instead:
            // later than we would like, but on disk rather than in memory.
            if (filesize($tempPath) > self::MAX_DOWNLOAD_BYTES) {
                Log::warning('OutboundMedia: download exceeded the size limit', [
                    'url' => $this->url,
                    'bytes' => filesize($tempPath),
                ]);
                @unlink($tempPath);

                return null;
            }

            $contentType = $response->header('Content-Type') ?: 'application/octet-stream';
            $mime = trim(explode(';', $contentType)[0]);

            $filename = $this->filename;
            if ($filename === '' || !str_contains($filename, '.')) {
                $ext = self::extensionFromMime($mime) ?: ($this->extension ?: '');
                $base = $filename !== '' ? $filename : 'attachment';
                $filename = $base . ($ext ? ".{$ext}" : '');
            }

            // Already on disk — the sink above wrote it while it streamed.
            return new UploadedFile($tempPath, $filename, $mime, null, true);
        } catch (ValidationException $th) {
            // A refused address is the caller's mistake, not a download that
            // went wrong, and it has to reach them as one.
            throw $th;
        } catch (\Throwable $th) {
            Log::error('OutboundMedia: failed to download attachment', [
                'url' => $this->url,
                'error' => $th->getMessage(),
            ]);

            if (isset($tempPath) && is_string($tempPath)) {
                @unlink($tempPath);
            }

            return null;
        }
    }

    /**
     * Refuse an address this server must not be made to fetch.
     *
     * Thrown as a validation error on `media_url` so every send endpoint
     * answers 422 on the field the caller actually sent — and so
     * MessageService::guard(), which lets ValidationException through
     * untouched, does not flatten it into a generic upstream failure.
     */
    private static function assertFetchable(string $url): void
    {
        if (PublicUrl::isFetchable($url)) {
            return;
        }

        Log::warning('OutboundMedia: refused a non-public media_url', [
            // Host only: the rest of a URL somebody sent us may carry their
            // own credentials.
            'host' => parse_url($url, PHP_URL_HOST),
        ]);

        throw ValidationException::withMessages([
            'media_url' => 'A mídia precisa estar em um endereço público na internet.',
        ]);
    }

    /**
     * Guess a file extension from a MIME type for common attachment formats.
     */
    public static function extensionFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg', 'audio/opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            default => null,
        };
    }

    /**
     * Guess a MIME type from a file extension (inverse of extensionFromMime).
     */
    public static function mimeFromExtension(string $extension): ?string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp3' => 'audio/mpeg',
            'ogg', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            default => null,
        };
    }
}
