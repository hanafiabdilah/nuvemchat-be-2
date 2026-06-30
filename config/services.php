<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Instagram (Meta) credentials live in the `settings` table (DB-only),
    // managed by super-admin in the Back Office → Integrations.
    // See App\Services\Connection\Meta\InstagramConfig.

    // Facebook (Meta) credentials for WhatsApp Cloud API & Messenger live in the
    // `settings` table (DB-only). See App\Services\Connection\Meta\FacebookConfig.

    // W-API integrator (managed) token lives in the `settings` table (DB-only).
    // See App\Services\Connection\WApi\WApiConfig.

    // ProxyHub credentials live in the `settings` table (DB-only), managed by
    // super-admin in the Back Office. See App\Services\Connection\Proxy\ProxyhubConfig.

    // AI Agent Hub credentials live in the `settings` table (DB-only).
    // See App\Services\AiAgentHub\AiAgentHubConfig.

    'mercadopago' => [
        // Credentials (access_token, public_key, webhook_secret, back_url) live in
        // the `settings` table (DB-only) — see App\Services\Billing\MercadoPago\MercadoPagoConfig.
        // Only operational toggles stay here.
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
        'enforce' => (bool) env('BILLING_ENFORCE', false),
    ],

];
