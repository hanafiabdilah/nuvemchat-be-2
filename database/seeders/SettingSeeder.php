<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\Billing\MercadoPago\MercadoPagoConfig;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Connection\Meta\InstagramConfig;
use App\Services\Connection\Proxy\ProxyhubConfig;
use App\Services\Connection\WApi\WApiConfig;
use App\Services\Notification\NotificationConfig;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Seed default platform settings. ProxyHub credentials are DB-only; the
     * base URL gets a sensible default, and any existing PROXYHUB_INTEGRATOR_TOKEN
     * in .env is migrated into the DB once so a working setup isn't lost.
     */
    public function run(): void
    {
        if (Setting::get(ProxyhubConfig::KEY_BASE_URL) === null) {
            Setting::set(ProxyhubConfig::KEY_BASE_URL, ProxyhubConfig::DEFAULT_BASE_URL);
        }

        // One-time migration of the legacy env token (if present and not yet set).
        $envToken = env('PROXYHUB_INTEGRATOR_TOKEN');
        if (! empty($envToken) && Setting::get(ProxyhubConfig::KEY_INTEGRATOR_TOKEN) === null) {
            Setting::set(ProxyhubConfig::KEY_INTEGRATOR_TOKEN, $envToken);
        }

        // One-time migration of MercadoPago credentials from .env into the DB.
        $mpEnv = [
            MercadoPagoConfig::KEY_ACCESS_TOKEN => env('MERCADOPAGO_ACCESS_TOKEN'),
            MercadoPagoConfig::KEY_PUBLIC_KEY => env('MERCADOPAGO_PUBLIC_KEY'),
            MercadoPagoConfig::KEY_WEBHOOK_SECRET => env('MERCADOPAGO_WEBHOOK_SECRET'),
            MercadoPagoConfig::KEY_BACK_URL => env('MERCADOPAGO_BACK_URL'),
        ];
        foreach ($mpEnv as $key => $value) {
            if (! empty($value) && Setting::get($key) === null) {
                Setting::set($key, $value);
            }
        }

        // One-time migration of channel/integration credentials from .env into
        // the DB. After seeding, these can be removed from .env (DB is the source
        // of truth — managed in the Back Office → Integrations).
        $envCredentials = [
            InstagramConfig::KEY_CLIENT_ID => env('INSTAGRAM_CLIENT_ID'),
            InstagramConfig::KEY_CLIENT_SECRET => env('INSTAGRAM_CLIENT_SECRET'),
            InstagramConfig::KEY_REDIRECT_URI => env('INSTAGRAM_REDIRECT_URI'),
            InstagramConfig::KEY_WEBHOOK_VERIFY_TOKEN => env('INSTAGRAM_WEBHOOK_VERIFY_TOKEN'),

            FacebookConfig::KEY_APP_ID => env('FACEBOOK_APP_ID'),
            FacebookConfig::KEY_APP_SECRET => env('FACEBOOK_APP_SECRET'),
            FacebookConfig::KEY_WEBHOOK_VERIFY_TOKEN => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
            FacebookConfig::KEY_CONFIG_ID => env('FACEBOOK_CONFIG_ID'),

            WApiConfig::KEY_MANAGED_TOKEN => env('WAPI_MANAGED_TOKEN'),

            AiAgentHubConfig::KEY_BASE_URL => env('AI_AGENT_HUB_BASE_URL'),
            AiAgentHubConfig::KEY_ADMIN_TOKEN => env('AI_AGENT_HUB_ADMIN_TOKEN'),
        ];
        foreach ($envCredentials as $key => $value) {
            if (! empty($value) && Setting::get($key) === null) {
                Setting::set($key, $value);
            }
        }

        // Notification defaults (blueprint): disabled until a super-admin configures
        // the provider in Back Office → Integrations → Notifications.
        $notificationDefaults = [
            NotificationConfig::KEY_ENABLED => '0',
            NotificationConfig::KEY_PROVIDER => NotificationConfig::DEFAULT_PROVIDER,
            NotificationConfig::KEY_PINGLY_BASE_URL => NotificationConfig::DEFAULT_PINGLY_BASE_URL,
            NotificationConfig::KEY_WAPI_BASE_URL => NotificationConfig::DEFAULT_WAPI_BASE_URL,
            NotificationConfig::KEY_PROXYBR_BASE_URL => ProxyhubConfig::DEFAULT_BASE_URL,
        ];
        foreach ($notificationDefaults as $key => $value) {
            if (Setting::get($key) === null) {
                Setting::set($key, $value);
            }
        }

        $this->command->info('Platform settings seeded.');
    }
}
