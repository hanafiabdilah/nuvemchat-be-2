<?php

namespace App\Services\Billing\MercadoPago;

use App\Models\Setting;

/**
 * Single source of truth for MercadoPago credentials. Credentials live in the
 * `settings` table (managed by super-admin in the Back Office → Integrations) —
 * not in .env. Operational toggles (enforce, grace days) stay in config.
 */
class MercadoPagoConfig
{
    public const KEY_ACCESS_TOKEN = 'mercadopago.access_token';
    public const KEY_PUBLIC_KEY = 'mercadopago.public_key';
    public const KEY_WEBHOOK_SECRET = 'mercadopago.webhook_secret';
    public const KEY_BACK_URL = 'mercadopago.back_url';

    public const BASE_URL = 'https://api.mercadopago.com';

    public static function accessToken(): ?string
    {
        return Setting::get(self::KEY_ACCESS_TOKEN);
    }

    public static function publicKey(): ?string
    {
        return Setting::get(self::KEY_PUBLIC_KEY);
    }

    public static function webhookSecret(): ?string
    {
        return Setting::get(self::KEY_WEBHOOK_SECRET);
    }

    public static function backUrl(): ?string
    {
        return Setting::get(self::KEY_BACK_URL);
    }

    public static function baseUrl(): string
    {
        return self::BASE_URL;
    }
}
