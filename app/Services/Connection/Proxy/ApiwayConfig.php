<?php

namespace App\Services\Connection\Proxy;

use App\Models\Setting;

/**
 * Single source of truth for API Way platform credentials. Stored in the
 * `settings` table (managed by super-admin in the Back Office) — not in .env.
 */
class ApiwayConfig
{
    // The `proxyhub.*` prefix predates the API Way rebrand. It is the primary key of
    // rows already in the `settings` table, so it stays as-is.
    public const KEY_BASE_URL = 'proxyhub.base_url';
    public const KEY_INTEGRATOR_TOKEN = 'proxyhub.integrator_token';
    public const KEY_PARTNER_BASE_URL = 'proxyhub.partner_base_url';
    public const KEY_PARTNER_TOKEN = 'proxyhub.partner_token';

    /** Default used until an admin overrides it in the database. */
    public const DEFAULT_BASE_URL = 'https://whats-api.ipbr.pro';

    /** ProxyBR portal hosting the partner (reseller) API surface. */
    public const DEFAULT_PARTNER_BASE_URL = 'https://portal.proxybr.com.br';

    public static function baseUrl(): string
    {
        $url = Setting::get(self::KEY_BASE_URL) ?: self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    public static function integratorToken(): ?string
    {
        return Setting::get(self::KEY_INTEGRATOR_TOKEN);
    }

    public static function partnerBaseUrl(): string
    {
        $url = Setting::get(self::KEY_PARTNER_BASE_URL) ?: self::DEFAULT_PARTNER_BASE_URL;

        return rtrim($url, '/');
    }

    public static function partnerToken(): ?string
    {
        return Setting::get(self::KEY_PARTNER_TOKEN);
    }
}
