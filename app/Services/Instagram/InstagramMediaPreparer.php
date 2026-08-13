<?php

namespace App\Services\Instagram;

use App\Exceptions\InstagramApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns whatever the user dragged in into something Instagram will accept.
 *
 * This exists because Meta's publishing API is unusually strict and unusually
 * unhelpful about it: a PNG — the single most common thing a person uploads —
 * is refused outright with "The submitted image is not a valid JPEG", after the
 * post has been queued and the container built. Converting up front turns a
 * confusing late failure into no failure at all.
 *
 * Two more constraints are enforced here for the same reason: the 8 MB ceiling
 * and the 4:5 – 1.91:1 aspect window. Anything outside the window is fitted
 * rather than rejected, because "your image is 3 pixels too tall" is not a
 * thing a marketing team can act on.
 *
 * Files land on the `public` disk, not the private one everything else uses.
 * That is not a slip: the content publishing API takes no bytes for images, it
 * takes a URL and downloads from it, so Meta's servers must be able to read the
 * file. It follows that publishing cannot work from a laptop — APP_URL has to
 * resolve from the public internet.
 */
class InstagramMediaPreparer
{
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    /** Meta's feed window, as width ÷ height. */
    public const MIN_RATIO = 4 / 5;
    public const MAX_RATIO = 1.91;

    /** Meta scales anything smaller up, and anything larger down; do it here. */
    public const MIN_EDGE = 320;
    public const MAX_EDGE = 1920;

    /**
     * What GD can decode. Deliberately a short list: HEIC and AVIF are common
     * on phones but need Imagick with delegates that are not guaranteed on the
     * host, and silently mangling them would be worse than refusing them.
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];

    /** Cropping matches what the Instagram app does to an odd-shaped photo. */
    public const FIT_CROP = 'crop';
    public const FIT_PAD = 'pad';

    /**
     * Store an upload and return what the post item needs.
     *
     * @return array{media_type: string, url: string, path: string}
     */
    public function store(UploadedFile $file, int $tenantId, string $fit = self::FIT_CROP): array
    {
        $mime = (string) $file->getMimeType();

        if (in_array($mime, self::VIDEO_MIMES, true)) {
            return $this->storeVideo($file, $tenantId);
        }

        if (! in_array($mime, self::IMAGE_MIMES, true)) {
            throw new InstagramApiException(
                'Instagram accepts JPEG, PNG, WEBP and GIF images, or MP4 and MOV video.',
                httpStatus: 422,
            );
        }

        return $this->storeImage($file, $tenantId, $fit);
    }

    /**
     * Video is passed through untouched.
     *
     * We do not transcode: that needs ffmpeg, which is not a dependency of this
     * app, and Meta transcodes server-side anyway. The cost is that a video
     * Meta dislikes fails later, in the container status, rather than here —
     * which is why the publisher surfaces Meta's `status` text verbatim.
     */
    private function storeVideo(UploadedFile $file, int $tenantId): array
    {
        $path = $file->store($this->directory($tenantId), 'public');

        return [
            'media_type' => 'video',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ];
    }

    private function storeImage(UploadedFile $file, int $tenantId, string $fit): array
    {
        $source = $this->decode($file);

        try {
            $canvas = $this->normalize($source, $fit);

            try {
                $path = $this->directory($tenantId) . '/' . Str::uuid() . '.jpg';

                Storage::disk('public')->put($path, $this->encodeWithinLimit($canvas));

                return [
                    'media_type' => 'image',
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                ];
            } finally {
                if ($canvas !== $source) {
                    imagedestroy($canvas);
                }
            }
        } finally {
            imagedestroy($source);
        }
    }

    private function decode(UploadedFile $file)
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw new InstagramApiException('That image could not be read. Try re-saving it as a JPEG or PNG.', httpStatus: 422);
        }

        return $image;
    }

    /**
     * Resize into Meta's bounds and fit the aspect ratio.
     *
     * The target ratio is the *nearest* legal one rather than a fixed square:
     * a portrait photo stays portrait and a wide one stays wide, so the crop or
     * the bars are as small as the rules allow.
     */
    private function normalize($source, string $fit)
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = $width / max(1, $height);

        $targetRatio = min(self::MAX_RATIO, max(self::MIN_RATIO, $ratio));

        [$canvasWidth, $canvasHeight] = $this->canvasSize($width, $height, $targetRatio);

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        // JPEG has no alpha, so transparency has to become something. White
        // matches Instagram's own background and is also the pad colour.
        imagefilledrectangle($canvas, 0, 0, $canvasWidth, $canvasHeight, imagecolorallocate($canvas, 255, 255, 255));

        [$srcX, $srcY, $srcW, $srcH, $dstX, $dstY, $dstW, $dstH] =
            $fit === self::FIT_PAD
                ? $this->containBox($width, $height, $canvasWidth, $canvasHeight)
                : $this->coverBox($width, $height, $canvasWidth, $canvasHeight);

        imagecopyresampled($canvas, $source, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }

    /** Canvas dimensions at the target ratio, clamped to Meta's edge limits. */
    private function canvasSize(int $width, int $height, float $targetRatio): array
    {
        // Start from the source's longest edge so we never upscale needlessly.
        $canvasWidth = $targetRatio >= 1 ? max($width, (int) round($height * $targetRatio)) : $width;
        $canvasHeight = (int) round($canvasWidth / $targetRatio);

        $scale = min(1.0, self::MAX_EDGE / max($canvasWidth, $canvasHeight));
        $canvasWidth = (int) round($canvasWidth * $scale);
        $canvasHeight = (int) round($canvasHeight * $scale);

        // And up, if the source was tiny — Meta upscales below 320px itself,
        // usually worse than we do.
        $upscale = max(1.0, self::MIN_EDGE / max(1, min($canvasWidth, $canvasHeight)));

        return [
            max(1, (int) round($canvasWidth * $upscale)),
            max(1, (int) round($canvasHeight * $upscale)),
        ];
    }

    /** Centre-crop the source to fill the canvas (no bars, edges lost). */
    private function coverBox(int $width, int $height, int $canvasWidth, int $canvasHeight): array
    {
        $scale = max($canvasWidth / $width, $canvasHeight / $height);
        $srcW = (int) round($canvasWidth / $scale);
        $srcH = (int) round($canvasHeight / $scale);

        return [
            (int) round(($width - $srcW) / 2), (int) round(($height - $srcH) / 2), $srcW, $srcH,
            0, 0, $canvasWidth, $canvasHeight,
        ];
    }

    /** Fit the whole source inside the canvas (bars, nothing lost). */
    private function containBox(int $width, int $height, int $canvasWidth, int $canvasHeight): array
    {
        $scale = min($canvasWidth / $width, $canvasHeight / $height);
        $dstW = (int) round($width * $scale);
        $dstH = (int) round($height * $scale);

        return [
            0, 0, $width, $height,
            (int) round(($canvasWidth - $dstW) / 2), (int) round(($canvasHeight - $dstH) / 2), $dstW, $dstH,
        ];
    }

    /**
     * Encode as JPEG, stepping quality down until it fits the 8 MB ceiling.
     *
     * Quality 88 is where a normalised 1920px photo lands well under the limit;
     * the loop is the safety net for pathological images (noise, screenshots of
     * text) that do not compress.
     */
    private function encodeWithinLimit($canvas): string
    {
        foreach ([88, 75, 60, 45] as $quality) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $bytes = (string) ob_get_clean();

            if (strlen($bytes) <= self::MAX_IMAGE_BYTES) {
                return $bytes;
            }
        }

        throw new InstagramApiException('That image is too large for Instagram even after compression.', httpStatus: 422);
    }

    private function directory(int $tenantId): string
    {
        return "instagram/{$tenantId}/" . now()->format('Y/m');
    }
}
