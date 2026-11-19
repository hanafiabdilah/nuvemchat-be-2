<?php

/**
 * Agen AI dan kredensial (App\Services\AiAgentHub\AiHubFieldErrors).
 *
 * Nama field = label form di dashboard, supaya pesannya menunjuk field yang
 * sedang dilihat orangnya. lang/en, lang/id dan lang/pt_BR memakai key sama.
 */

return [

    'attributes' => [
        'name' => 'Nama',
        'description' => 'Deskripsi',
        'systemPrompt' => 'System prompt',
        'model' => 'Model',
        'temperature' => 'Temperature',
        'maxTokens' => 'Token maksimum',
        'apiKey' => 'API key',
        'defaultModel' => 'Model default',
    ],

    'rules' => [
        'min_length' => ':attribute minimal :min karakter.',
        'max_length' => ':attribute maksimal :max karakter.',
        'required' => 'Isi :attribute.',
        'min_value' => ':attribute minimal :min.',
        'max_value' => ':attribute maksimal :max.',
        'invalid' => 'Nilai :attribute tidak diterima. Periksa lalu coba lagi.',
        'too_large' => ':attribute terlalu panjang untuk disimpan. Persingkat teksnya lalu coba lagi.',
    ],

    'credential_status_fixed' => 'Status key tidak bisa diubah. Untuk berhenti memakainya, hapus key tersebut.',

];
