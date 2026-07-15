<?php

namespace App\Services\Message;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            $response = Http::timeout(30)->get($this->url);

            if (!$response->successful()) {
                Log::warning('OutboundMedia: download returned non-success', [
                    'url' => $this->url,
                    'status' => $response->status(),
                ]);
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

            $tempPath = tempnam(sys_get_temp_dir(), 'media_');
            file_put_contents($tempPath, $response->body());

            return new UploadedFile($tempPath, $filename, $mime, null, true);
        } catch (\Throwable $th) {
            Log::error('OutboundMedia: failed to download attachment', [
                'url' => $this->url,
                'error' => $th->getMessage(),
            ]);
            return null;
        }
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
