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

    'billing' => [
        // Credentials (base URL, API key, webhook secret, preferred provider)
        // live in the `settings` table (DB-only) — see
        // App\Services\Billing\PaymentService\PaymentServiceConfig. Only
        // operational toggles stay here.
        //
        // Named after what it does rather than after a gateway: this block was
        // `mercadopago` while Pingly called one directly, and every reader of
        // `enforce` had nothing to do with that company.
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
        'enforce' => (bool) env('BILLING_ENFORCE', false),
    ],

    'whatsapp' => [
        // Enforce the WhatsApp-number verification gate (EnsureWhatsAppVerified) on the
        // tenant API. On by default; set WHATSAPP_VERIFY_ENFORCE=false to disable.
        'verify_enforce' => (bool) env('WHATSAPP_VERIFY_ENFORCE', true),
    ],

    'admin' => [
        // Refuse Back Office access to accounts with no second factor enrolled.
        //
        // OFF by default, and that default is load-bearing: turning it on
        // before the operators have enrolled locks every one of them out at
        // once — including whoever would have to turn it back off. Deploy,
        // let everyone enrol, watch Back Office → Health report nobody left,
        // then flip it. See App\Http\Middleware\EnsureAdminTwoFactor.
        'mfa_required' => (bool) env('ADMIN_MFA_REQUIRED', false),
    ],

    'webhooks' => [
        // Refuse a delivery to /webhook/chat/{id} for a connection that has no
        // secret stored yet, instead of serving it with a warning in the log.
        //
        // OFF by default, and that default is load-bearing: every connection
        // live today registered its webhook upstream before secrets existed, so
        // turning this on before `webhooks:secure-chat` has run would drop real
        // customer messages for every Telegram and API Way workspace at once.
        // A connection that HAS a secret is always strict, flag or no flag — so
        // the exposure closes one connection at a time as the command works
        // through them. Flip this once Back Office → Health reports nothing left
        // to secure. See App\Services\Webhook\ChatWebhookSecret.
        'chat_strict' => (bool) env('WEBHOOK_CHAT_STRICT', false),
    ],

];
