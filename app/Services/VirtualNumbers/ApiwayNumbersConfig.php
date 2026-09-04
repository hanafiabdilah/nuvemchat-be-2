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
 * The credential is a token the reseller generates in the API Way portal and
 * pastes here — the same shape as every other integration on this platform. The
 * portal also exposes `POST /login`, but storing an e-mail and a password to
 * mint a token we can already be handed would mean holding a password that
 * opens the whole account, to obtain something a copy button provides.
 */
class ApiwayNumbersConfig
{
    public const KEY_BASE_URL = 'apiway_numbers.base_url';

    /** API token generated in the API Way portal (Bearer). */
    public const KEY_TOKEN = 'apiway_numbers.token';

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

    public static function token(): ?string
    {
        return Setting::get(self::KEY_TOKEN);
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
        return ! empty(self::token());
    }
}
