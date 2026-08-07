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

    /**
     * OAuth redirect URI for the Messenger popup flow — always this backend's
     * own callback route (whitelist it in the Meta App dashboard). Deliberately
     * NOT a setting: a configurable facebook.redirect_uri was removed once
     * (see FacebookRedirectUriRemovedTest) because WhatsApp Embedded Signup
     * must never send one, and there is nothing else to configure — the code
     * can only ever land here.
     */
    public static function redirectUri(): string
    {
        return route('oauth.facebook.callback');
    }
}
