<?php

namespace App\Services\VirtualNumbers;

use App\Models\Setting;

/**
 * Credentials for the API Way *numbers* portal — a different account and a
 * different API from the ProxyBR partner surface that sells instances.
 *
 * ⚠️ Two things called "API Way" live in this codebase and they share nothing
 * but the brand. `ApiwayConfig` (proxyhub.*) talks to ProxyBR, who resell
 * WhatsApp instances to us. This one talks to API Way directly, with an account
 * of Pingly's own, and buys virtual phone numbers. Neither token works on the
 * other's endpoints.
 *
 * The credential is an e-mail and a password rather than a token because that
 * is the only thing the portal issues: `POST /login` returns a Sanctum token,
 * and a token we cannot re-mint is a platform that stops selling numbers the
 * first time somebody rotates it. The password is stored the same way every
 * other platform secret is — encrypted in `settings`, managed in the Back
 * Office, never in .env.
 */
class ApiwayNumbersConfig
{
    public const KEY_BASE_URL = 'apiway_numbers.base_url';

    public const KEY_EMAIL = 'apiway_numbers.email';

    public const KEY_PASSWORD = 'apiway_numbers.password';

    /**
     * HMAC secret returned by `PUT /numbers/webhook`. It is shown exactly once,
     * by that call — the GET only ever returns a preview — so this row is the
     * only copy that exists.
     */
    public const KEY_WEBHOOK_SECRET = 'apiway_numbers.webhook_secret';

    /** The URL we last registered, kept so the Back Office can show it. */
    public const KEY_WEBHOOK_URL = 'apiway_numbers.webhook_url';

    public const DEFAULT_BASE_URL = 'https://portal.apiway.com.br/api';

    public static function baseUrl(): string
    {
        $url = Setting::get(self::KEY_BASE_URL) ?: self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    public static function email(): ?string
    {
        return Setting::get(self::KEY_EMAIL);
    }

    public static function password(): ?string
    {
        return Setting::get(self::KEY_PASSWORD);
    }

    public static function webhookSecret(): ?string
    {
        return Setting::get(self::KEY_WEBHOOK_SECRET);
    }

    public static function webhookUrl(): ?string
    {
        return Setting::get(self::KEY_WEBHOOK_URL);
    }

    /** Whether the platform can sell numbers at all. */
    public static function isConfigured(): bool
    {
        return ! empty(self::email()) && ! empty(self::password());
    }
}
