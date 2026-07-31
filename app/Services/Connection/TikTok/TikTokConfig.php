<?php

namespace App\Services\Connection\TikTok;

use App\Models\Setting;

/**
 * Single source of truth for TikTok Business Messaging credentials.
 * Stored in the `settings` table (DB-only), managed by super-admin in the
 * Back Office → Integrations — not in .env.
 */
class TikTokConfig
{
    /** TikTok Business API host (OAuth + Business Messaging endpoints). */
    public const BASE_URL = 'https://business-api.tiktok.com';

    public const KEY_APP_ID = 'tiktok.app_id';
    public const KEY_APP_SECRET = 'tiktok.app_secret';
    public const KEY_REDIRECT_URI = 'tiktok.redirect_uri';

    public static function appId(): ?string
    {
        return Setting::get(self::KEY_APP_ID);
    }

    public static function appSecret(): ?string
    {
        return Setting::get(self::KEY_APP_SECRET);
    }

    public static function redirectUri(): ?string
    {
        return Setting::get(self::KEY_REDIRECT_URI);
    }
}
