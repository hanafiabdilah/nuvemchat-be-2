<?php

namespace App\Services\TikTok;

use App\Services\Media\ChunkedUpload;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Turns what the user picked into something TikTok will fetch and accept.
 *
 * Everything lands on the *published* disk under `tiktok/`: TikTok downloads
 * from a URL, and only from the verified prefix (TikTokUrlProperty). Photos are
 * re-encoded — TikTok takes JPEG and WebP only, at most 1080×1920 — and videos
 * pass through untouched (TikTok transcodes them itself), arriving in pieces
 * because production PHP refuses a request over 64 MB.
 */
class TikTokMediaPreparer
{
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm'];

    /** TikTok's own ceiling for a video post. */
    public const MAX_VIDEO_BYTES = 1024 * 1024 * 1024;

    public const MAX_PHOTO_BYTES = 20 * 1024 * 1024;

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function chunks(): ChunkedUpload
    {
        return new ChunkedUpload('tiktok', self::VIDEO_EXTENSIONS, self::MAX_VIDEO_BYTES, MediaStorage::published());
    }

    public static function directory(int $tenantId): string
    {
        return TikTokUrlProperty::DIRECTORY.'/'.$tenantId;
    }

    /** @return array{media_type: string, url: string, path: string} */
    public function storePhoto(UploadedFile $file, int $tenantId): array
    {
        if (! in_array((string) $file->getMimeType(), self::IMAGE_MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'TikTok photos must be JPEG, PNG, WEBP or GIF images.']);
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw ValidationException::withMessages(['file' => 'That image could not be read. Try re-saving it as a JPEG or PNG.']);
        }

        try {
            $canvas = $this->fit($source);

            try {
                $path = MediaFilename::path(self::directory($tenantId), MediaFilename::token(), $file->getClientOriginalName(), 'jpg');
                MediaStorage::published()->put($path, $this->encode($canvas));

                return ['media_type' => 'image', 'path' => $path, 'url' => MediaStorage::publishedUrl($path)];
            } finally {
                if ($canvas !== $source) {
                    imagedestroy($canvas);
                }
            }
        } finally {
            imagedestroy($source);
        }
    }

    /** @return array{media_type: string, url: string, path: string} */
    public static function videoFile(string $path): array
    {
        return ['media_type' => 'video', 'path' => $path, 'url' => MediaStorage::publishedUrl($path)];
    }

    /**
     * Shrink into 1080×1920 (portrait) or 1920×1080 (landscape). Never cropped
     * and never enlarged: TikTok shows a photo post at its own shape.
     */
    private function fit($source)
    {
        $width = imagesx($source);
        $height = imagesy($source);
        [$maxW, $maxH] = $width > $height ? [1920, 1080] : [1080, 1920];
        $scale = min(1.0, $maxW / $width, $maxH / $height);

        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        // JPEG has no alpha; transparency becomes white.
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        return $canvas;
    }

    private function encode($canvas): string
    {
        foreach ([90, 80, 65] as $quality) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $bytes = (string) ob_get_clean();

            if (strlen($bytes) <= self::MAX_PHOTO_BYTES) {
                return $bytes;
            }
        }

        throw ValidationException::withMessages(['file' => 'That image is too large for TikTok even after compression.']);
    }
}
