<?php

return [

    /*
    | Tutorial videos (Back Office → Tutorials, dashboard "Video tutorials").
    |
    | `max_upload_mb`: the largest file the Back Office accepts. Uploads arrive
    | in 8 MB pieces (App\Services\Media\ChunkedUpload), so this is independent
    | of PHP's upload_max_filesize and of any proxy in front.
    |
    | `fallback_locale`: a topic with no video in the reader's language shows
    | the videos in this one instead — most tutorials are recorded in
    | Portuguese first, and a video in another language beats no video.
    |
    | `max_long_side` / `max_bitrate`: what a file is re-encoded down to before
    | it is served. Every play is a download from our storage to somebody on a
    | phone connection, and a 4K, 20 Mbps phone recording buys nothing over
    | 1080p for a screen recording of the dashboard.
    */
    'max_upload_mb' => (int) env('TUTORIAL_MAX_UPLOAD_MB', 500),

    'fallback_locale' => env('TUTORIAL_FALLBACK_LOCALE', 'pt_BR'),

    'max_long_side' => (int) env('TUTORIAL_MAX_LONG_SIDE', 1920),

    'max_bitrate' => (int) env('TUTORIAL_MAX_BITRATE', 6_000_000),

];
