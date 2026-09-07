<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a user's photo is written, replaced and deleted.
 *
 * Two screens set the same file — an admin on the agents page, the person
 * themselves on their profile — and a third (the new-agent wizard) sets it a
 * moment after the account exists. Keeping the storage rules here means the
 * validation, the disk, the naming and the "delete what it replaced" step
 * cannot drift between them.
 *
 * Files go on the private disk with the rest of the product's media, not on
 * `public`: serving them needs no `storage:link`, and the reader gets a signed
 * URL from User::avatar_url. Nothing sweeps this directory — an avatar is not
 * message media (see media:purge), it lives until it is replaced or the
 * account is deleted.
 */
class AvatarStorage
{
    public const DIRECTORY = 'avatars';

    /**
     * The ceiling on the stored file. Generous on purpose: the dashboard
     * shrinks a picture before it uploads it, so anything approaching this
     * arrived from somewhere else, and rejecting a phone camera's output is a
     * worse outcome than storing it.
     */
    public const MAX_KILOBYTES = 5120;

    /**
     * Longest edge of what actually gets stored. An avatar is drawn at 48px at
     * its largest, and the agents page draws one per row: a page that fetched
     * twenty untouched camera photos would spend tens of megabytes to fill a
     * column of circles.
     */
    public const MAX_DIMENSION = 512;

    /**
     * Formats accepted, spelled out rather than left to the `image` rule.
     * SVG is a document that can carry script, and it is served from our own
     * origin — an avatar is never worth that.
     */
    public const EXTENSIONS = ['jpeg', 'jpg', 'png', 'webp'];

    /** @return array<int, string> */
    public static function rules(): array
    {
        return [
            'required',
            'image',
            'mimes:' . implode(',', self::EXTENSIONS),
            'max:' . self::MAX_KILOBYTES,
        ];
    }

    /**
     * Store this upload as the user's photo and forget the previous one.
     *
     * The row is written before the old file is deleted, so a failure to unlink
     * leaves an orphaned file rather than a user pointing at a file that is no
     * longer there.
     */
    public function store(User $user, UploadedFile $file): User
    {
        $previous = $user->avatar_path;

        $binary = (string) file_get_contents($file->getRealPath());
        $extension = $this->extensionFor($file);

        $shrunk = $this->shrink($binary, $extension);

        // A filename nobody can guess: the signature is what gates the URL, and
        // a predictable `avatars/7.jpg` would make the id half of a guess.
        $path = self::DIRECTORY . '/' . $user->id . '_' . Str::random(16) . '.' . $extension;

        $this->disk()->put($path, $shrunk ?? $binary);

        $user->forceFill(['avatar_path' => $path])->save();

        $this->delete($previous);

        return $user;
    }

    /** Drop the photo, leaving the account on its initials fallback. */
    public function clear(User $user): User
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return $user;
        }

        $user->forceFill(['avatar_path' => null])->save();

        $this->delete($previous);

        return $user;
    }

    /**
     * Remove the file without touching the row — for a user being deleted,
     * where there is no row left to point at it.
     */
    public function forget(User $user): void
    {
        $this->delete($user->avatar_path);
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }

    private function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            // A leftover file costs disk; failing the request over it would
            // cost the user the change they just made.
            Log::warning('AvatarStorage: could not delete the previous avatar', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extensionFor(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        return in_array($extension, self::EXTENSIONS, true) ? $extension : 'jpg';
    }

    /**
     * The same picture, no longer than MAX_DIMENSION on its longest edge, or
     * null when it is already small enough — or when this build cannot resize
     * at all.
     *
     * Every failure path returns null and the original bytes get stored: a
     * missing GD extension or an image type it cannot decode is a reason to
     * skip the optimisation, never a reason to refuse the upload.
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
                // Transparent PNGs and WebPs otherwise re-encode with a black
                // backdrop, which on a circular avatar reads as a broken crop.
                imagealphablending($resized, false);
                imagesavealpha($resized, true);

                return $this->encode($resized, $extension);
            } finally {
                imagedestroy($resized);
            }
        } catch (Throwable $e) {
            Log::warning('AvatarStorage: resize failed, storing the original', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            imagedestroy($source);
        }
    }

    /** @param \GdImage $image */
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
