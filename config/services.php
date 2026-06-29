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

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect_uri' => env('INSTAGRAM_REDIRECT_URI'),
        'webhook_verify_token' => env('INSTAGRAM_WEBHOOK_VERIFY_TOKEN'),
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
        'config_id' => env('FACEBOOK_CONFIG_ID'), // WhatsApp Business Config ID for embedded signup
    ],

    'wapi' => [
        'managed_token' => env('WAPI_MANAGED_TOKEN'),
    ],

    'proxyhub' => [
        'base_url' => env('PROXYHUB_BASE_URL', 'https://whats-api.ipbr.pro'),
        'integrator_token' => env('PROXYHUB_INTEGRATOR_TOKEN'),
    ],

    'ai_agent_hub' => [
        'base_url' => env('AI_AGENT_HUB_BASE_URL', 'https://api-ia.ipbr.pro/v1'),
        'admin_token' => env('AI_AGENT_HUB_ADMIN_TOKEN'),
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'), // exposed to frontend Bricks
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
        'back_url' => env('MERCADOPAGO_BACK_URL'),
        // Grace period (days) before a past_due subscription is suspended.
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
        // Master switch for enforcement middleware (rollout safety).
        'enforce' => (bool) env('BILLING_ENFORCE', false),
    ],

];
