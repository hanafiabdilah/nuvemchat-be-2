<?php

namespace App\Services\Connection\Proxy;

use App\Models\Setting;

/**
 * Single source of truth for ProxyHub platform credentials. Stored in the
 * `settings` table (managed by super-admin in the Back Office) — not in .env.
 */
class ProxyhubConfig
{
    public const KEY_BASE_URL = 'proxyhub.base_url';
    public const KEY_INTEGRATOR_TOKEN = 'proxyhub.integrator_token';

    /** Default used until an admin overrides it in the database. */
    public const DEFAULT_BASE_URL = 'https://whats-api.ipbr.pro';

    public static function baseUrl(): string
    {
        $url = Setting::get(self::KEY_BASE_URL) ?: self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    public static function integratorToken(): ?string
    {
        return Setting::get(self::KEY_INTEGRATOR_TOKEN);
    }
}
