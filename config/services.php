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

    // API Way credentials live in the `settings` table (DB-only), managed by
    // super-admin in the Back Office. See App\Services\Connection\Proxy\ApiwayConfig.

    // AI Agent Hub credentials live in the `settings` table (DB-only).
    // See App\Services\AiAgentHub\AiAgentHubConfig.

    'apiway' => [
        // Credentials (base_url, integrator/partner tokens) live in the
        // `settings` table — see App\Services\Connection\Proxy\ApiwayConfig.
        // How long a paid purchase waits while ProxyBR is at its platform cap
        // before we give up and flag it for refund.
        'capacity_hold_hours' => (int) env('APIWAY_CAPACITY_HOLD_HOURS', 24),
    ],

    'mercadopago' => [
        // Credentials (access_token, public_key, webhook_secret, back_url) live in
        // the `settings` table (DB-only) — see App\Services\Billing\MercadoPago\MercadoPagoConfig.
        // Only operational toggles stay here.
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
        'enforce' => (bool) env('BILLING_ENFORCE', false),
    ],

    'whatsapp' => [
        // Enforce the WhatsApp-number verification gate (EnsureWhatsAppVerified) on the
        // tenant API. On by default; set WHATSAPP_VERIFY_ENFORCE=false to disable.
        'verify_enforce' => (bool) env('WHATSAPP_VERIFY_ENFORCE', true),
    ],

];
