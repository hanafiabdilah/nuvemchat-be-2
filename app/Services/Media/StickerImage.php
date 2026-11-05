<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turning a picture into something a channel will draw as a sticker.
 *
 * Every channel that has stickers wants the same file: a WebP. WhatsApp (Cloud
 * API and the core behind API Way alike) declares it `image/webp`, 512×512,
 * ≤100 KB when static, ≤500 KB when animated; Telegram fetches a sticker by
 * URL only when it is a `.webp`. A PNG with a transparent background — which
 * is what most people have when they say "this is my sticker", and what the
 * AI media node produces — is none of those things, and the channel does not
 * convert it for us: Meta refuses it, Telegram refuses it.
 *
 * So the file is made into one here, once, rather than left for each channel
 * to reject in its own words.
 *
 * ⚠️ An **animated** WebP is passed through untouched. GD decodes a single
 * frame and nothing more, so "converting" one would quietly ship a still image
 * in place of the animation somebody chose — the one outcome worse than a
 * refusal, because it looks like it worked. An animated GIF is the exception
 * that proves it: it is not a sticker format anywhere, so its first frame is
 * the best there is, and that is what it becomes.
 */
final class StickerImage
{
    /** The canvas WhatsApp draws a sticker on. Smaller files are scaled up into it. */
    public const SIZE = 512;

    /** WhatsApp's ceilings. A static sticker is re-encoded until it fits. */
    public const STATIC_MAX_BYTES = 100 * 1024;

    public const ANIMATED_MAX_BYTES = 500 * 1024;

    /**
     * Past this many pixels the file is refused before it is decoded. GD holds
     * four bytes a pixel, so a 10 MB JPEG from a phone camera can unpack to
     * hundreds of megabytes inside a web request; no sticker needs that.
     */
    private const MAX_SOURCE_PIXELS = 40_000_000;

    /** Tried in order until the static sticker is under its ceiling. */
    private const QUALITIES = [90, 80, 70, 60, 50, 40, 30];

    public static function isWebp(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP';
    }

    /**
     * Whether a WebP holds an animation: the extended (`VP8X`) header carries
     * an animation flag, and only that header can.
     */
    public static function isAnimated(string $bytes): bool
    {
        return self::isWebp($bytes)
            && strlen($bytes) > 20
            && substr($bytes, 12, 4) === 'VP8X'
            && (ord($bytes[20]) & 0x02) !== 0;
    }

    /**
     * Whether these bytes can go out as a sticker exactly as they are.
     */
    public static function isReady(string $bytes): bool
    {
        if (! self::isWebp($bytes)) {
            return false;
        }

        if (self::isAnimated($bytes)) {
            return true;
        }

        $size = @getimagesizefromstring($bytes);

        return is_array($size)
            && $size[0] === self::SIZE
            && $size[1] === self::SIZE
            && strlen($bytes) <= self::STATIC_MAX_BYTES;
    }

    /**
     * The file as a sticker: the bytes themselves when they already are one,
     * otherwise a 512×512 WebP with the picture centred on a transparent canvas.
     *
     * Null when GD cannot read the file at all (not an image, or too large to
     * decode) — the caller turns that into a sentence for the person.
     */
    public static function toWebp(string $bytes): ?string
    {
        if (self::isReady($bytes) || self::isAnimated($bytes)) {
            return $bytes;
        }

        if (! function_exists('imagewebp')) {
            Log::error('StickerImage: GD was built without WebP, so no sticker can be made');

            return null;
        }

        $canvas = self::canvas($bytes);

        if ($canvas === null) {
            return null;
        }

        try {
            $encoded = null;

            foreach (self::QUALITIES as $quality) {
                $encoded = self::encode(fn () => imagewebp($canvas, null, $quality));

                if ($encoded !== null && strlen($encoded) <= self::STATIC_MAX_BYTES) {
                    return $encoded;
                }
            }

            // Still over the ceiling at the lowest quality: a photograph full
            // of noise. Sent anyway — the channel's refusal names the problem
            // better than a guess here would, and most will take it.
            return $encoded;
        } finally {
            imagedestroy($canvas);
        }
    }

    /**
     * The same sticker as a PNG, for the channels that have no stickers and do
     * not take WebP as a picture either (Instagram, Messenger). Transparency is
     * kept, which is most of what makes a sticker look like one.
     *
     * Null for an animated WebP: GD would hand back one frame, see above.
     */
    public static function toPng(string $bytes): ?string
    {
        if (self::isAnimated($bytes)) {
            return null;
        }

        $canvas = self::canvas($bytes);

        if ($canvas === null) {
            return null;
        }

        try {
            return self::encode(fn () => imagepng($canvas, null, 6));
        } finally {
            imagedestroy($canvas);
        }
    }

    /**
     * The picture fitted inside a transparent 512×512 square, aspect kept.
     *
     * @return \GdImage|null
     */
    private static function canvas(string $bytes)
    {
        $size = @getimagesizefromstring($bytes);

        if (! is_array($size) || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        if ($size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            Log::warning('StickerImage: refusing to decode a very large image', [
                'width' => $size[0],
                'height' => $size[1],
            ]);

            return null;
        }

        try {
            $source = @imagecreatefromstring($bytes);
        } catch (Throwable) {
            $source = false;
        }

        if ($source === false) {
            return null;
        }

        try {
            // A palette image (GIF, PNG-8) keeps its transparent colour only
            // once it is truecolor; copied straight, it comes out on black.
            if (! imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(self::SIZE / $width, self::SIZE / $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);

            if ($canvas === false) {
                return null;
            }

            // Blending off while painting, so the transparent fill and the
            // picture's own alpha are written as they are instead of being
            // composited onto the black a new truecolor image starts as.
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefilledrectangle(
                $canvas, 0, 0, self::SIZE - 1, self::SIZE - 1,
                imagecolorallocatealpha($canvas, 0, 0, 0, 127),
            );

            imagecopyresampled(
                $canvas,
                $source,
                intdiv(self::SIZE - $targetWidth, 2),
                intdiv(self::SIZE - $targetHeight, 2),
                0,
                0,
                $targetWidth,
                $targetHeight,
                $width,
                $height,
            );

            return $canvas;
        } catch (Throwable $e) {
            Log::warning('StickerImage: could not draw the sticker', ['error' => $e->getMessage()]);

            return null;
        } finally {
            imagedestroy($source);
        }
    }

    private static function encode(callable $write): ?string
    {
        ob_start();

        try {
            $ok = $write();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $ok && $output !== '' ? $output : null;
    }
}
