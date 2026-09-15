<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Off on purpose: private media is served by our own route at the
            // same /storage/{path} address (MediaFileController), which works
            // for whichever disk holds the bytes. Laravel's would only ever
            // look here, and two routes on one URI is a coin toss.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Media on object storage (Vultr, S3-compatible): two disks over one
        | bucket, kept apart by prefix. `media` (private/) is only ever reached
        | through presigned URLs handed out by the signed media route; objects
        | under `media_public` (public/) are world-readable, because their URLs
        | are stored in flows, campaigns and scheduled posts and sent for months.
        | Selected through MEDIA_DISK / MEDIA_OUTBOUND_DISK / MEDIA_PUBLISHED_DISK
        | (config/media.php) — see docs/media-object-storage.md.
        */

        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_S3_KEY'),
            'secret' => env('MEDIA_S3_SECRET'),
            'region' => env('MEDIA_S3_REGION', 'us-east-1'),
            'bucket' => env('MEDIA_S3_BUCKET'),
            'endpoint' => env('MEDIA_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'root' => 'private',
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'media_public' => [
            'driver' => 's3',
            'key' => env('MEDIA_S3_KEY'),
            'secret' => env('MEDIA_S3_SECRET'),
            'region' => env('MEDIA_S3_REGION', 'us-east-1'),
            'bucket' => env('MEDIA_S3_BUCKET'),
            'endpoint' => env('MEDIA_S3_ENDPOINT'),
            'url' => env('MEDIA_S3_PUBLIC_URL'),
            'use_path_style_endpoint' => true,
            'root' => 'public',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
