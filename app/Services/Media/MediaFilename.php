<?php

namespace App\Services\Media;

use Illuminate\Support\Str;

/**
 * The name a stored file is written under.
 *
 * ⚠️ This is not housekeeping, and it is not only a storage concern: the last
 * segment of the stored path is the name a human reads. Four surfaces derive
 * from it and none of them has another source:
 *
 *  - the document bubble in the dashboard prints it (FE `DocumentMessage`);
 *  - "save file" writes it to the agent's disk (FE `mediaFileName`);
 *  - `MediaFileController` serves it, so the browser names the download by it;
 *  - `OutboundMedia::fromData()` reads it back off a `media_url` as both the
 *    MIME type to announce *and* the filename — and on Instagram and Messenger
 *    that URL-derived name is the only name the customer ever sees, because
 *    those two channels take no `filename` field at all (the one we record in
 *    `meta` never leaves this platform).
 *
 * So `media/4812_68d1a2f3b4c5d.pdf` was not an internal detail. It was the name
 * the agent downloaded and, on two channels, the name the customer was asked to
 * open. A name carries what the file is; a hash carries nothing, and the person
 * who has to act on it is left guessing.
 *
 * The rules, and why each one is the way it is:
 *
 *  - The original base name survives. That is the whole point.
 *
 *  - A unique suffix goes *after* it, never in place of it. Some of these
 *    directories are flat and shared across every tenant (`media/`,
 *    `uploads/`), so two people sending `catalogo.pdf` must not land on one
 *    path — the second write would silently replace the first, and the first
 *    person's flow node would carry on sending somebody else's file.
 *
 *  - The result is ASCII. Accents are transliterated rather than dropped, so
 *    `Relatório` stays readable as `Relatorio`. Non-ASCII would survive a URL
 *    (Laravel percent-encodes, the SPA already decodes), but these keys are
 *    mid-migration onto object storage, where a non-ASCII key that upsets
 *    presigning is a silent media outage rather than a visible error.
 *
 *  - Case and `_` survive; whitespace becomes `-`. Everything a path or a URL
 *    could read as structure is stripped, because this string is interpolated
 *    into both.
 *
 * The pristine name — accents, spaces, capitals intact — is kept separately in
 * `messages.meta.filename` and is what the dashboard shows when it is there.
 * This is the durable fallback for everything that has no such record.
 */
final class MediaFilename
{
    /**
     * Longest base name kept. Bounded because the full key also carries a
     * directory, the suffix and the extension, and object-storage keys and
     * filesystem path segments both have ceilings.
     */
    private const MAX_BASE = 80;

    /** Used when the original name is missing or sanitizes away to nothing. */
    private const FALLBACK = 'arquivo';

    /**
     * Build the last segment of a stored path.
     *
     * @param  string|null  $original   the name the file arrived with; any directory part is ignored
     * @param  string       $extension  derived from the *content*, not from `$original` — see UploadPolicy::storedExtension()
     * @param  string|null  $unique     a natural key when one exists (a message id makes a retried
     *                                  download idempotent instead of orphaning the first attempt);
     *                                  omitted, a random code is generated
     * @param  string|null  $fallback   base name when `$original` yields nothing usable
     */
    public static function build(
        ?string $original,
        string $extension,
        ?string $unique = null,
        ?string $fallback = null,
    ): string {
        $base = self::base($original, $fallback);
        $suffix = self::suffix($unique);
        $extension = self::extension($extension);

        return $base.'_'.$suffix.($extension !== '' ? '.'.$extension : '');
    }

    /**
     * The readable part: the original name, reduced to characters that cannot
     * change the meaning of a path or a URL.
     */
    public static function base(?string $original, ?string $fallback = null): string
    {
        // basename() first: the name is attacker-controlled on every upload
        // path, and `../../etc/passwd` must lose its directory before anything
        // else looks at it.
        $name = basename(str_replace('\\', '/', trim((string) $original)));
        $name = pathinfo($name, PATHINFO_FILENAME);

        // Transliterate before filtering, or every accented letter is simply
        // deleted and `Relatório` arrives as `Relatrio`.
        $name = Str::ascii($name);

        $name = preg_replace('/\s+/u', '-', $name) ?? '';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';
        $name = preg_replace('/[-_]{2,}/', '-', $name) ?? '';
        $name = preg_replace('/\.{2,}/', '.', $name) ?? '';

        // A segment that is only dots is path traversal, and one that ends in a
        // dot would print as `report._a3f2.pdf`.
        $name = trim($name, '.-_');

        if ($name === '') {
            $name = self::clean($fallback) ?: self::FALLBACK;
        }

        return Str::limit($name, self::MAX_BASE, '');
    }

    /**
     * The machine part.
     *
     * 12 hex characters where there is no natural key: `uploads/` is flat and
     * shared, so the collision bound has to hold across every tenant's files
     * rather than one directory's.
     */
    private static function suffix(?string $unique): string
    {
        $unique = self::clean($unique);

        return $unique !== '' ? Str::limit($unique, 40, '') : bin2hex(random_bytes(6));
    }

    private static function extension(string $extension): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '');
    }

    private static function clean(?string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', Str::ascii(trim((string) $value))) ?? '';

        return trim($value, '.-_');
    }
}
