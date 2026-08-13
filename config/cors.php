<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Live Chat Widget SDK di-embed di website pihak ketiga, jadi seluruh
    | endpoint /widget-api/* harus menerima request dari domain manapun.
    |
    | Otorisasi channel Echo ada di /api/broadcasting/auth (lihat
    | bootstrap/app.php) sehingga sudah tercakup oleh 'api/*'. Path lama
    | 'broadcasting/auth' tidak pernah didaftarkan lagi; widget memakai channel
    | publik dan tidak melakukan handshake auth sama sekali.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'widget-api/*',
        'widget-api',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
