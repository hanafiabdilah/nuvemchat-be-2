<?php

namespace App\Services\Media;

use Illuminate\Support\Str;

/**
 * Where a stored file goes, and what it is called when it gets there.
 *
 * ⚠️ The last segment of a stored path is not an internal detail — it is a name
 * a human reads, and four surfaces derive from it with no other source:
 *
 *  - the document bubble in the dashboard prints it (FE `DocumentMessage`);
 *  - "save file" writes it to the agent's disk (FE `mediaFileName`);
 *  - `MediaFileController` serves it, so the browser names the download by it;
 *  - `OutboundMedia::fromData()` reads it back off a `media_url` as both the
 *    MIME type to announce *and* the filename — and on Instagram and Messenger
 *    that URL-derived name is the only name the customer ever sees, because
 *    those two take no `filename` field at all.
 *
 * So the name is the product, and it is kept whole: `Contrato-de-Servico.pdf`,
 * with nothing appended.
 *
 * **Uniqueness lives in the directory, never in the name.** That is the one
 * rule that makes a clean name safe, and it is not optional: `media/` and
 * `uploads/` are flat and shared across every tenant, so two businesses
 * sending `catalogo.pdf` would otherwise land on one path — the second write
 * replaces the first, and the first one's flow node carries on sending
 * somebody else's file. Inside a workspace it is no better: a message archive
 * has to be immutable, and a second `Proposta.pdf` must not retroactively
 * change what an earlier conversation shows.
 *
 * The gallery has worked this way since it shipped — the uuid is in the path
 * and `public_filename` is the bare name — and this is the rest of the platform
 * catching up to it.
 *
 * Prefer a natural key for the directory: a message id makes a retried download
 * overwrite its own previous attempt instead of orphaning it on disk. Where
 * there is no such key (an upload, a temporary public copy), `token()`.
 *
 * The name keeps the customer's spelling — spaces, accents, parentheses. That
 * is only safe because the constraint lives where it belongs: a URL cannot
 * carry a raw space or a raw UTF-8 byte, so `MediaStorage::encodePath()` encodes
 * when a path becomes a URL and the readers decode on the way back. Mangling the
 * stored name to sidestep that was the cheap fix, and it made every surface pay
 * for one boundary's rule.
 *
 * What is still removed is only what cannot survive a path: control characters,
 * the separators, the characters Windows refuses in a filename, and the three
 * that are structural in a URL (`#`, `?`, `%` — the last because a literal
 * percent makes a decode ambiguous wherever one happens without a matching
 * encode).
 */
final class MediaFilename
{
    /**
     * Longest base name kept, in BYTES rather than characters: an accented
     * letter is two bytes, and both the 255-byte limit on a filesystem path
     * segment and the 1024-byte limit on an object-storage key count bytes.
     */
    private const MAX_BASE_BYTES = 150;

    /** Used when the original name is missing or sanitizes away to nothing. */
    private const FALLBACK = 'arquivo';

    /**
     * The full relative path a file should be stored at.
     *
     * @param  string       $folder     the area it belongs to, e.g. `media`, `uploads`
     * @param  string       $unique     what makes this file's own directory — a natural
     *                                  key when one exists, otherwise `token()`. May carry
     *                                  slashes (an email's message id plus its part index).
     * @param  string|null  $original   the name the file arrived with; any directory part is ignored
     * @param  string       $extension  derived from the *content*, not from `$original` — see UploadPolicy::storedExtension()
     * @param  string|null  $fallback   base name when `$original` yields nothing usable
     */
    public static function path(
        string $folder,
        string $unique,
        ?string $original,
        string $extension,
        ?string $fallback = null,
    ): string {
        $directory = self::directory($folder.'/'.$unique);
        $name = self::name($original, $extension, $fallback);

        return $directory === '' ? $name : $directory.'/'.$name;
    }

    /**
     * Just the filename: the original name, reduced to characters that cannot
     * change the meaning of a path or a URL, plus its real extension.
     *
     * No uniqueness suffix — see the class note. Only use this directly where
     * the caller already guarantees the directory is this file's alone.
     */
    public static function name(?string $original, string $extension, ?string $fallback = null): string
    {
        $extension = self::extension($extension);
        $base = self::base($original, $fallback);

        return $extension !== '' ? $base.'.'.$extension : $base;
    }

    /**
     * A directory name for a file with no natural key.
     *
     * 12 hex characters: `uploads/` is shared by every tenant, so the collision
     * bound has to hold across the whole platform's files rather than one
     * workspace's.
     */
    public static function token(): string
    {
        return bin2hex(random_bytes(6));
    }

    /** The readable part, without its extension. */
    public static function base(?string $original, ?string $fallback = null): string
    {
        // basename() first: the name is attacker-controlled on every upload
        // path, and `../../etc/passwd` must lose its directory before anything
        // else looks at it.
        $name = basename(str_replace('\\', '/', trim((string) $original)));
        $name = self::sanitize(pathinfo($name, PATHINFO_FILENAME));

        if ($name === '') {
            $name = self::sanitize((string) $fallback) ?: self::FALLBACK;
        }

        // mb_strcut, not Str::limit: cutting at a byte boundary inside a
        // multibyte letter leaves a name that is not valid UTF-8, and the JSON
        // cast on `messages.meta` refuses to encode one of those.
        return rtrim(mb_strcut($name, 0, self::MAX_BASE_BYTES, 'UTF-8'), ' .');
    }

    /**
     * Strip what a path cannot carry, and nothing else.
     *
     * Spaces, accents, parentheses and `&` all survive — they are legal in a
     * filesystem name and in an object-storage key, and `MediaStorage::encodePath()`
     * makes them legal in a URL.
     */
    private static function sanitize(string $value): string
    {
        // A name that is not valid UTF-8 (an old mail client sending Latin-1)
        // would make every /u pattern below return null, and would be rejected
        // by the JSON cast later anyway.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = Str::ascii($value);
        }

        // Control characters; the separators; what Windows refuses in a
        // filename; and `#`/`?`/`%`, which are structural in a URL.
        // ⚠️ `\\\\`, not `\\`: in a single-quoted string the latter reaches PCRE as
        // one backslash, which then escapes the `:` and quietly leaves the
        // backslash itself out of the class. base() happens to convert them to
        // slashes first, so nothing was reachable — but the comment above would
        // have been a lie the next time somebody called this directly.
        $value = preg_replace('~[\x00-\x1F\x7F/\\\\:*?"<>|#%]+~u', '', $value) ?? '';

        // A name is one line, and a run of spaces in a path is a trap nobody
        // means to set.
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = preg_replace('/\.{2,}/', '.', $value) ?? '';

        // A segment of only dots is traversal, and a leading or trailing dot or
        // space is hostile to half the filesystems that will ever hold it.
        return trim($value, " .\t\n\r\0\x0B");
    }

    /**
     * Sanitize a directory path segment by segment.
     *
     * Callers build these from ids, but they are still interpolated into a
     * storage path, and a segment that survives as `..` would move the file.
     */
    private static function directory(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            $segment = self::clean($segment);

            if ($segment !== '') {
                $segments[] = Str::limit($segment, 64, '');
            }
        }

        return implode('/', $segments);
    }

    private static function extension(string $extension): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');
    }

    /** Directory segments stay strict: they are ours, built from ids. */
    private static function clean(?string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', Str::ascii(trim((string) $value))) ?? '';

        return trim($value, '.-_');
    }
}
