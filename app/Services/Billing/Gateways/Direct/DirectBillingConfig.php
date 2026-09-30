<?php

namespace App\Services\Billing\Gateways\Direct;

use App\Models\Setting;

/**
 * Pingly's own gateway accounts, used when PAYMENT_METHOD=direct.
 *
 * Two accounts, split by market rather than by method: Mercado Pago takes every
 * Brazilian charge (card and Pix), dLocal Go takes every other country. Stored
 * in `settings` like every platform credential and edited in Back Office →
 * Integrations → Payments, which shows these fields only while the env says
 * `direct` — the payment-service form is what shows otherwise.
 *
 * ⚠️ Deliberately separate keys from the workspace-level Mercado Pago
 * integration (`integrations` table, flow node Payment). That one is a
 * customer's own account receiving their customers' money; this one is the
 * platform's account receiving subscriptions. Sharing a key would be a way to
 * bill Pingly plans into a customer's wallet.
 */
class DirectBillingConfig
{
    public const MP_ACCESS_TOKEN = 'billing_direct.mercadopago.access_token';

    public const MP_PUBLIC_KEY = 'billing_direct.mercadopago.public_key';

    /** From "Your integrations → Webhooks" in the Mercado Pago panel. */
    public const MP_WEBHOOK_SECRET = 'billing_direct.mercadopago.webhook_secret';

    public const DLOCALGO_API_KEY = 'billing_direct.dlocalgo.api_key';

    public const DLOCALGO_SECRET_KEY = 'billing_direct.dlocalgo.secret_key';

    /** '1' = sandbox host. dLocal Go keys belong to one mode and fail in the other. */
    public const DLOCALGO_SANDBOX = 'billing_direct.dlocalgo.sandbox';

    /**
     * The SmartFields key: what lets the card form live on our checkout page
     * instead of dLocal Go's. Issued by dLocal Go support on request (it is not
     * in their dashboard) and different from the API key. Public by design — it
     * is handed to the browser — so it is stored and shown in the clear.
     *
     * Empty = no card form: the market is sold the hosted checkout only, which
     * is exactly how it behaved before this key existed.
     */
    public const DLOCALGO_SMARTFIELDS_KEY = 'billing_direct.dlocalgo.smartfields_key';

    public const MP_BASE_URL = 'https://api.mercadopago.com';

    public const DLOCALGO_PRODUCTION_URL = 'https://api.dlocalgo.com';

    public const DLOCALGO_SANDBOX_URL = 'https://api-sbx.dlocalgo.com';

    public const DLOCALGO_SMARTFIELDS_PRODUCTION_SDK = 'https://checkout.dlocalgo.com/js/dlocalgo-smartfields-bundled.js';

    public const DLOCALGO_SMARTFIELDS_SANDBOX_SDK = 'https://checkout-sbx.dlocalgo.com/js/dlocalgo-smartfields-bundled.js';

    public static function mpAccessToken(): ?string
    {
        return Setting::get(self::MP_ACCESS_TOKEN) ?: null;
    }

    public static function mpPublicKey(): ?string
    {
        return Setting::get(self::MP_PUBLIC_KEY) ?: null;
    }

    public static function mpWebhookSecret(): ?string
    {
        return Setting::get(self::MP_WEBHOOK_SECRET) ?: null;
    }

    public static function dlocalGoApiKey(): ?string
    {
        return Setting::get(self::DLOCALGO_API_KEY) ?: null;
    }

    public static function dlocalGoSecretKey(): ?string
    {
        return Setting::get(self::DLOCALGO_SECRET_KEY) ?: null;
    }

    public static function dlocalGoSandbox(): bool
    {
        return in_array((string) Setting::get(self::DLOCALGO_SANDBOX), ['1', 'true'], true);
    }

    public static function dlocalGoSmartFieldsKey(): ?string
    {
        return Setting::get(self::DLOCALGO_SMARTFIELDS_KEY) ?: null;
    }

    /** The SDK host follows the same sandbox switch as the API: a sandbox checkout token means nothing to the production script. */
    public static function dlocalGoSmartFieldsSdkUrl(): string
    {
        return self::dlocalGoSandbox() ? self::DLOCALGO_SMARTFIELDS_SANDBOX_SDK : self::DLOCALGO_SMARTFIELDS_PRODUCTION_SDK;
    }

    public static function dlocalGoBaseUrl(): string
    {
        return self::dlocalGoSandbox() ? self::DLOCALGO_SANDBOX_URL : self::DLOCALGO_PRODUCTION_URL;
    }

    /** Read-only in the Back Office: pasted into the Mercado Pago panel. */
    public static function mpWebhookUrl(): string
    {
        return route('webhook.billing.mercadopago');
    }

    /** Sent on every dLocal Go payment; shown so an operator can recognise it. */
    public static function dlocalGoWebhookUrl(): string
    {
        return route('webhook.billing.dlocalgo');
    }

    /**
     * Where a customer lands after a hosted checkout or a card authorisation.
     * The dashboard, not our API: they are a person, not a webhook.
     */
    public static function returnUrl(string $path = '/billing'): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
