<?php

return [
    /*
    | Largest video the YouTube composer accepts, in MB.
    |
    | The dashboard sends videos in 8 MB pieces (YoutubeChunkedUpload), so this
    | is not bounded by PHP's upload_max_filesize — it is bounded by the disk:
    | the file sits on the media disk until YouTube has it, and on object
    | storage each upload pass copies it to local temp. YouTube itself takes up
    | to 256 GB.
    */
    'max_upload_mb' => (int) env('YOUTUBE_MAX_UPLOAD_MB', 2048),
];
