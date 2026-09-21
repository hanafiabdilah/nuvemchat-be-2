<?php

namespace App\Services\Media;

/**
 * What a customer is allowed to upload, and what we are allowed to serve back.
 *
 * Two upload paths used to accept anything at all — POST /api/uploads (flow
 * attachments, carousel cards, campaign media) and the gallery — and both land
 * on an address under our own domain. An HTML or SVG file therefore became a
 * page on `chat.pingly.com.br`, which is the origin that serves the tenant
 * dashboard *and* the Back Office at /webmin, with session tokens in
 * localStorage and no Content-Security-Policy to stop a script reading them.
 * One link opened by a platform admin was a complete platform takeover.
 *
 * The list below is not a guess: it is the union of what the channel send
 * handlers already accept (WhatsApp, Telegram, Discord, Instagram, Messenger,
 * e-mail, widget), plus the audio containers the AI transcription path names.
 * Anything outside it could be stored but never actually delivered, so nothing
 * that works today is being taken away.
 *
 * ⚠️ Deliberately absent, and why:
 *
 *   svg, svgz          — an SVG is a document that can carry <script>, and it
 *                        is the shortest path from "picture upload" to XSS.
 *   html, htm, xhtml   — the vector itself.
 *   xml                — renders as a document and carries XSLT/entities.
 *   js, mjs            — nothing here needs to host a script.
 *   php, phtml, htaccess, swf — never useful, historically dangerous.
 *
 * ⚠️ The extension list alone is NOT the whole defence. Laravel's `mimes` rule
 * compares the extension it guesses from the file's *content*, so a polyglot —
 * a file that is a valid GIF and valid HTML at once — passes as `gif` and is
 * stored as `.gif`. It is only harmless because it is then served with
 * `X-Content-Type-Options: nosniff`, which stops the browser second-guessing
 * `image/gif`. Uploading and serving have to be fixed together; see
 * safeContentType() and the /storage block in deploy/Caddyfile.
 */
final class UploadPolicy
{
    /**
     * Extensions accepted on customer upload paths.
     *
     * Matched by Laravel against the extension it derives from the file's
     * content, not from the name the browser sent.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        // Images
        'jpeg', 'jpg', 'png', 'gif', 'webp',
        // Audio. `oga`/`mpga`/`mpeg`/`flac` are here because the AI
        // transcription path names them; the rest come from the send handlers.
        'mp3', 'ogg', 'oga', 'opus', 'wav', 'm4a', 'aac', 'amr', 'flac', 'mpga', 'mpeg',
        // Video. `webm` serves both audio and video and is listed once.
        'mp4', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'webm',
        // Documents. `txt` is not decoration: a real .csv is detected as
        // text/plain, so without it every CSV upload would be refused.
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        // Archives. Not renderable by a browser, so not an XSS carrier, and
        // already accepted by the widget and e-mail paths.
        'zip', 'rar',
    ];

    /**
     * Content types a browser will render as a document if it is allowed to.
     *
     * Used when serving, not when uploading: rows stored before this policy
     * existed still carry whatever was detected then, and a stored `text/html`
     * must not be echoed back as one. Matching is on the type we recorded, so
     * it is cheap and needs no migration.
     *
     * @var list<string>
     */
    private const RENDERABLE_TYPES = [
        'text/html',
        'text/xml',
        'application/xml',
        'application/xhtml+xml',
        'image/svg+xml',
        'image/svg',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'application/xhtml',
    ];

    /**
     * Validation rules for a customer upload.
     *
     * Mirrors AvatarStorage::rules() so both upload surfaces are configured
     * the same way — a rule array the caller hands straight to validate().
     *
     * @return list<string>
     */
    public static function rules(int $maxKilobytes): array
    {
        return [
            'required',
            'file',
            'mimes:'.implode(',', self::EXTENSIONS),
            'max:'.$maxKilobytes,
        ];
    }

    /**
     * The refusal a customer reads. Laravel's default for `mimes` prints the
     * whole list, which at thirty extensions is a wall rather than an answer.
     */
    public static function message(): string
    {
        return 'Tipo de arquivo não suportado. Envie imagem, vídeo, áudio, PDF, documento do Office, texto ou arquivo compactado.';
    }

    /** Whether this file's content type is one a browser would run. */
    public static function isRenderable(?string $mime): bool
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return $mime !== '' && in_array($mime, self::RENDERABLE_TYPES, true);
    }

    /**
     * The content type it is safe to answer with.
     *
     * Anything a browser would render as a document comes back as an opaque
     * download instead. Upload validation should already make this
     * unreachable; it is here for the rows that predate it, which no amount of
     * input validation can retroactively fix.
     */
    public static function safeContentType(?string $mime): string
    {
        $mime = trim((string) $mime);

        if ($mime === '' || self::isRenderable($mime)) {
            return 'application/octet-stream';
        }

        return $mime;
    }
}
