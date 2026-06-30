<?php

namespace App\Services\Connection\Meta;

use App\Models\Setting;

/**
 * Single source of truth for Instagram (Meta) OAuth/webhook credentials.
 * Stored in the `settings` table (DB-only), managed by super-admin in the
 * Back Office → Integrations — not in .env.
 */
class InstagramConfig
{
    public const KEY_CLIENT_ID = 'instagram.client_id';
    public const KEY_CLIENT_SECRET = 'instagram.client_secret';
    public const KEY_REDIRECT_URI = 'instagram.redirect_uri';
    public const KEY_WEBHOOK_VERIFY_TOKEN = 'instagram.webhook_verify_token';

    public static function clientId(): ?string
    {
        return Setting::get(self::KEY_CLIENT_ID);
    }

    public static function clientSecret(): ?string
    {
        return Setting::get(self::KEY_CLIENT_SECRET);
    }

    public static function redirectUri(): ?string
    {
        return Setting::get(self::KEY_REDIRECT_URI);
    }

    public static function webhookVerifyToken(): ?string
    {
        return Setting::get(self::KEY_WEBHOOK_VERIFY_TOKEN);
    }
}
