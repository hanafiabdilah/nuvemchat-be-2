<?php

return [

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

];
