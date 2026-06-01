<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Live Chat Widget SDK di-embed di website pihak ketiga, jadi seluruh
    | endpoint /widget-api/* harus menerima request dari domain manapun.
    | broadcasting/auth ikut diizinkan agar Echo bisa subscribe dari widget.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'widget-api/*',
        'widget-api',
        'broadcasting/auth',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
