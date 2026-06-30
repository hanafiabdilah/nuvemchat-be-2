<?php

namespace App\Services\Connection\WApi;

use App\Models\Setting;

/**
 * Single source of truth for the W-API integrator (managed) token. Stored in
 * the `settings` table (DB-only), managed by super-admin in the Back Office →
 * Integrations — not in .env.
 */
class WApiConfig
{
    public const KEY_MANAGED_TOKEN = 'wapi.managed_token';

    public static function managedToken(): ?string
    {
        return Setting::get(self::KEY_MANAGED_TOKEN);
    }
}
