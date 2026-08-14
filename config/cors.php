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
    | 'storage/*' = rute media (Storage::temporaryUrl disk local). Menampilkan
    | gambar via <img> tak butuh CORS, tapi MEMBACA byte-nya dari JS butuh —
    | dan itu satu-satunya cara menyimpan lampiran ke disk atau menyalinnya ke
    | clipboard, karena atribut `download` diabaikan pada URL lintas-origin
    | (SPA dan API beda domain). Tidak menambah paparan: URL-nya sudah signed +
    | kedaluwarsa, dan siapa pun yang memegangnya sudah bisa mengambilnya tanpa
    | browser. Tak ada cookie yang ikut ('supports_credentials' => false).
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'widget-api/*',
        'widget-api',
        'storage/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
