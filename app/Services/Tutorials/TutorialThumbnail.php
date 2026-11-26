<?php

namespace App\Services\Tutorials;

use App\Jobs\ProcessTutorialVideo;
use App\Models\TutorialVideo;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The thumbnail an admin picks for a tutorial video, in place of the frame
 * ffmpeg draws from it.
 *
 * Optional, and kept apart from that frame (`thumbnail_path` vs
 * `poster_path`): removing it brings the drawn frame back, and replacing the
 * video file keeps it — the admin chose this picture for this video, not for
 * one particular upload of it.
 *
 * Shrunk on the way in: it is drawn at most 480 px wide in the dashboard, and
 * every viewer of every page with a tutorial downloads it.
 */
final class TutorialThumbnail
{
    /** Longest edge kept — the drawer's player at 2x. */
    public const MAX_DIMENSION = 1280;

    public const MAX_KILOBYTES = 5120;

    /** No SVG: a document that can carry script, served from our origin. */
    public const EXTENSIONS = ['jpeg', 'jpg', 'png', 'webp'];

    /** @return array<int, string> */
    public static function rules(): array
    {
        return [
            'required',
            'file',
            'image',
            'mimes:'.implode(',', self::EXTENSIONS),
            'max:'.self::MAX_KILOBYTES,
        ];
    }

    /**
     * Store this upload as the video's thumbnail and forget the previous one.
     * The row is written before the old file is deleted: a failed unlink
     * leaves an orphaned file, never a video pointing at nothing.
     */
    public function store(TutorialVideo $video, UploadedFile $file): TutorialVideo
    {
        $extension = $this->extensionFor($file);
        $binary = (string) file_get_contents($file->getRealPath());
        $contents = $this->shrink($binary, $extension) ?? $binary;

        $path = 'tutorials/thumbnails/'.MediaFilename::token().'/thumbnail.'.$extension;
        MediaStorage::published()->put($path, $contents);

        $previous = $video->thumbnail_path;
        $video->update(['thumbnail_path' => $path]);

        if ($previous && $previous !== $path) {
            ProcessTutorialVideo::deleteFiles([$previous]);
        }

        return $video;
    }

    /** Back to the frame drawn from the video. */
    public function clear(TutorialVideo $video): TutorialVideo
    {
        $previous = $video->thumbnail_path;

        if ($previous) {
            $video->update(['thumbnail_path' => null]);
            ProcessTutorialVideo::deleteFiles([$previous]);
        }

        return $video;
    }

    private function extensionFor(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        if ($extension === 'jpeg') {
            return 'jpg';
        }

        return in_array($extension, self::EXTENSIONS, true) ? $extension : 'jpg';
    }

    /**
     * The same picture, no longer than MAX_DIMENSION on its longest edge, or
     * null when it is already small enough or this build cannot resize. Every
     * failure stores the original: a missing GD is a reason to skip the
     * optimisation, never to refuse the upload.
     */
    private function shrink(string $binary, string $extension): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $longest = max($width, $height);

            if ($longest <= self::MAX_DIMENSION) {
                return null;
            }

            $scale = self::MAX_DIMENSION / $longest;
            $resized = imagescale($source, (int) round($width * $scale), (int) round($height * $scale));

            if ($resized === false) {
                return null;
            }

            try {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);

                return $this->encode($resized, $extension);
            } finally {
                imagedestroy($resized);
            }
        } catch (Throwable $e) {
            Log::warning('TutorialThumbnail: resize failed, storing the original', ['error' => $e->getMessage()]);

            return null;
        } finally {
            imagedestroy($source);
        }
    }

    /** @param  \GdImage  $image */
    private function encode($image, string $extension): ?string
    {
        ob_start();

        $encoded = match ($extension) {
            'png' => imagepng($image, null, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($image, null, 85) : false,
            default => imagejpeg($image, null, 85),
        };

        $output = (string) ob_get_clean();

        return $encoded && $output !== '' ? $output : null;
    }
}
