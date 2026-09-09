<?php

namespace App\Services\Billing\PaymentService;

use App\Models\Setting;

/**
 * Credentials for the group's own payment service.
 *
 * Pingly no longer talks to a gateway. It talks to one internal service that
 * owns every gateway account — dLocal, MercadoPago, OpenPIX — and decides which
 * one takes a given charge. That is the whole point: swapping a provider, or
 * adding one, is a change over there and nothing at all over here.
 *
 * Stored in `settings` and managed in Back Office → Integrations → Payment
 * Service, like every other platform credential. Nothing here belongs in .env:
 * an operator rotating an API key should not need a deploy.
 */
class PaymentServiceConfig
{
    public const KEY_BASE_URL = 'payment_service.base_url';

    /** Per-product API key, minted once by the payment service admin. */
    public const KEY_API_KEY = 'payment_service.api_key';

    /**
     * HMAC secret for inbound webhooks. Shown exactly once, by the call that
     * mints it, so this row is the only copy that exists.
     */
    public const KEY_WEBHOOK_SECRET = 'payment_service.webhook_secret';

    /**
     * Which gateway to ask for, or empty to let the service route.
     *
     * A request, not a command: the service refuses rather than quietly
     * falling back, because the whole point of naming one is that it mattered.
     */
    public const KEY_PROVIDER = 'payment_service.provider';

    public const DEFAULT_BASE_URL = 'https://gateway.proxybr.com.br/api/v1';

    /**
     * How far a webhook's signed timestamp may drift before we refuse it.
     *
     * The timestamp is inside the signed string, not merely beside it, so
     * without this check a captured request can be replayed for as long as the
     * secret lives.
     */
    public const WEBHOOK_TOLERANCE_SECONDS = 300;

    /** Providers the service can route to, for the Back Office selector. */
    public const PROVIDERS = ['dlocal', 'dlocalgo', 'mercadopago', 'openpix'];

    public static function baseUrl(): string
    {
        $url = Setting::get(self::KEY_BASE_URL) ?: self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    public static function apiKey(): ?string
    {
        return Setting::get(self::KEY_API_KEY);
    }

    public static function webhookSecret(): ?string
    {
        return Setting::get(self::KEY_WEBHOOK_SECRET);
    }

    /** Null when the service should route on its own. */
    public static function provider(): ?string
    {
        $provider = Setting::get(self::KEY_PROVIDER);

        return in_array($provider, self::PROVIDERS, true) ? $provider : null;
    }

    /** Where the payment service should post events. Read-only; shown in the BO. */
    public static function webhookUrl(): string
    {
        return route('webhook.payments');
    }

    /** Whether the platform can take money at all. */
    public static function isConfigured(): bool
    {
        return ! empty(self::apiKey());
    }
}
