<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where media lives
    |--------------------------------------------------------------------------
    |
    | Three roles, each a disk name from config/filesystems.php, all reached
    | only through App\Services\Media\MediaStorage:
    |
    | - disk: private media behind signed links (attachments, avatars, contact
    |   photos, widget uploads, e-mail).
    | - outbound_disk: short-lived copies a channel fetches while we send.
    | - published_disk: files whose URL is stored in flows and campaigns and
    |   sent for months — that URL must never expire.
    |
    | The defaults are where everything has always been. Moving to object
    | storage is changing these, not editing channels.
    |
    */

    'disk' => env('MEDIA_DISK', 'local'),

    'outbound_disk' => env('MEDIA_OUTBOUND_DISK', 'public'),

    'published_disk' => env('MEDIA_PUBLISHED_DISK', 'public'),

    /*
    | Set only while media is being moved to a new disk (the old one, e.g.
    | "local"): a signed link to a file that has not been copied yet is still
    | served from here. Costs one existence check per request on the new disk,
    | so it is removed once `media:migrate --dry-run` reports nothing missing.
    | See docs/media-object-storage.md.
    */

    'legacy_disk' => env('MEDIA_LEGACY_DISK'),

    /*
    |--------------------------------------------------------------------------
    | Media retention
    |--------------------------------------------------------------------------
    |
    | How long an inbound/outbound file stays on our disk before `media:purge`
    | deletes it. Only the file goes: the message row, its caption and its
    | place in the thread stay exactly where they are, and the bubble turns
    | into an "expired media" marker.
    |
    | Group threads are the expensive ones — every member's photo and video
    | lands in our storage whether or not anyone here ever opens it — so they
    | get the shorter window.
    |
    */

    'retention' => [

        'enabled' => (bool) env('MEDIA_RETENTION_ENABLED', true),

        'group_days' => (int) env('MEDIA_RETENTION_GROUP_DAYS', 30),

        'private_days' => (int) env('MEDIA_RETENTION_PRIVATE_DAYS', 90),

    ],

    /*
    | Signed media URLs are handed out with an expiry equal to the file's purge
    | date, so a URL cached in the browser dies exactly when the file it points
    | at does. This value is only the fallback for when retention is switched
    | off entirely and there is no purge date to align with — a URL still has
    | to expire eventually, since the SPA stores it in IndexedDB and anyone
    | holding it can read the file without logging in.
    */

    'url_ttl_days' => (int) env('MEDIA_URL_TTL_DAYS', 180),

    /*
    | Widget visitors upload a file first and send it in a second call. An
    | upload that never became a message is referenced by nothing and would sit
    | on disk forever, so `media:purge` sweeps unreferenced ones. The window
    | only has to outlast the 6h upload URL by a comfortable margin.
    */

    'widget_upload_ttl_hours' => (int) env('MEDIA_WIDGET_UPLOAD_TTL_HOURS', 24),

    /*
    | Where ffmpeg lives. Audio recorded in a browser arrives in a container
    | the channel may refuse (Chrome records WebM, which WhatsApp rejects), so
    | `AudioNormalizer` re-encodes it on the way out.
    |
    | Left as a bare name so the shell resolves it on PATH, which is what the
    | container image provides; set an absolute path only where the binary is
    | somewhere PATH does not reach. An unset or missing binary is not a crash
    | — the send fails with a sentence telling the agent which formats work.
    */

    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),

    /*
    | ffprobe, ffmpeg's sibling: reads a video's duration and dimensions before
    | it is compressed. Same image, same package, so the same PATH rule.
    */
    'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),

    /*
    | Videos an agent sends from the composer (App\Services\Media\VideoCompressor,
    | App\Jobs\SendCompressedVideo).
    |
    | `max_upload_mb` is what the composer accepts — not what any channel
    | accepts. A file over its channel's own limit (Channel::videoMaxBytes(),
    | 10–50 MB) is compressed to fit before it is sent, on a queue: a long
    | video takes minutes of ffmpeg, which is no thing to hold a PHP worker
    | and the agent's request open for.
    |
    | The upload itself arrives in 8 MB pieces (ChunkedUpload), so this number
    | is independent of PHP's upload_max_filesize and of any proxy in front.
    |
    | `queue`: ⚠️ compressing is the heaviest thing this platform runs, and it
    | runs one at a time per worker. On `media` (the default, no deployment
    | step) a long video holds back inbound media downloads while it encodes;
    | give it its own worker and set VIDEO_COMPRESS_QUEUE once that matters.
    |
    | `timeout_seconds` bounds one ffmpeg run. The job's own timeout is this
    | plus a margin for the download and the send (see the job).
    |
    | `threads` keeps one encode from taking every core the dashboard and the
    | webhooks also need; ffmpeg also runs under `nice` where it exists.
    */
    'video' => [
        'max_upload_mb' => (int) env('VIDEO_MAX_UPLOAD_MB', 200),
        'queue' => env('VIDEO_COMPRESS_QUEUE', env('MEDIA_QUEUE', 'default')),
        'timeout_seconds' => (int) env('VIDEO_COMPRESS_TIMEOUT', 900),
        'threads' => (int) env('VIDEO_COMPRESS_THREADS', 2),
        'staging_disk' => env('VIDEO_STAGING_DISK', 'outbound-staging'),
    ],

    /*
    | poppler's pdftoppm, which turns the first page of a PDF receipt into a
    | picture a vision model can read (App\Services\Media\PdfPreview). Without
    | it a PDF sent to a Receipt node is answered as unreadable.
    */
    'pdftoppm_path' => env('PDFTOPPM_PATH', 'pdftoppm'),

];
