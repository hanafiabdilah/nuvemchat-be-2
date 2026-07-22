<?php

namespace App\Services\Connection\Meta;

use App\Models\Setting;

/**
 * Single source of truth for Facebook (Meta) credentials used for WhatsApp
 * Cloud API & Messenger. Stored in the `settings` table (DB-only), managed by
 * super-admin in the Back Office → Integrations — not in .env.
 */
class FacebookConfig
{
    public const KEY_APP_ID = 'facebook.app_id';
    public const KEY_APP_SECRET = 'facebook.app_secret';
    public const KEY_WEBHOOK_VERIFY_TOKEN = 'facebook.webhook_verify_token';
    public const KEY_CONFIG_ID = 'facebook.config_id';

    public static function appId(): ?string
    {
        return Setting::get(self::KEY_APP_ID);
    }

    public static function appSecret(): ?string
    {
        return Setting::get(self::KEY_APP_SECRET);
    }

    public static function webhookVerifyToken(): ?string
    {
        return Setting::get(self::KEY_WEBHOOK_VERIFY_TOKEN);
    }

    /** WhatsApp Business Config ID used for embedded signup. */
    public static function configId(): ?string
    {
        return Setting::get(self::KEY_CONFIG_ID);
    }
}
