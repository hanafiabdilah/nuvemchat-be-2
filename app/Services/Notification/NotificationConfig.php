<?php

namespace App\Services\Notification;

use App\Enums\Notification\NotificationType;
use App\Models\Setting;
use App\Services\Connection\Proxy\ProxyhubConfig;

/**
 * Single source of truth for platform notification settings. All values live in
 * the `settings` table (DB-only), managed by super-admin in the Back Office →
 * Integrations → Notifications. Secrets are encrypted at rest via the Setting model.
 */
class NotificationConfig
{
    public const KEY_ENABLED = 'notifications.enabled';
    public const KEY_PROVIDER = 'notifications.provider';
    public const KEY_EVENTS = 'notifications.events';

    // Pingly — the platform's own public API (X-API-Key auth, /send-message with
    // { to, message }). This is NOT the raw w-api.app endpoint.
    public const KEY_PINGLY_BASE_URL = 'notifications.pingly.base_url';
    public const KEY_PINGLY_API_KEY = 'notifications.pingly.api_key';

    // W-API (Directly) — a W-API instance the operator configures directly.
    public const KEY_WAPI_BASE_URL = 'notifications.wapi.base_url';
    public const KEY_WAPI_INSTANCE_ID = 'notifications.wapi.instance_id';
    public const KEY_WAPI_TOKEN = 'notifications.wapi.token';

    // ProxyBR API (Directly) — a ProxyHub instance (client-level credentials,
    // not the platform integrator token).
    public const KEY_PROXYBR_BASE_URL = 'notifications.proxybr.base_url';
    public const KEY_PROXYBR_INSTANCE_ID = 'notifications.proxybr.instance_id';
    public const KEY_PROXYBR_TOKEN = 'notifications.proxybr.token';

    public const DEFAULT_PROVIDER = 'pingly';
    public const DEFAULT_WAPI_BASE_URL = 'https://api.w-api.app/v1';
    public const DEFAULT_PINGLY_BASE_URL = 'https://chat.pingly.com.br/api/v1';

    /** Master switch — when off, nothing is ever dispatched. */
    public static function enabled(): bool
    {
        return filter_var(Setting::get(self::KEY_ENABLED), FILTER_VALIDATE_BOOL);
    }

    /** The active provider key (e.g. 'wapi'); resolved by NotificationProviderFactory. */
    public static function provider(): string
    {
        return Setting::get(self::KEY_PROVIDER, self::DEFAULT_PROVIDER);
    }

    public static function pinglyBaseUrl(): string
    {
        return rtrim((string) Setting::get(self::KEY_PINGLY_BASE_URL, self::DEFAULT_PINGLY_BASE_URL), '/');
    }

    public static function pinglyApiKey(): ?string
    {
        return Setting::get(self::KEY_PINGLY_API_KEY);
    }

    public static function wapiBaseUrl(): string
    {
        return rtrim((string) Setting::get(self::KEY_WAPI_BASE_URL, self::DEFAULT_WAPI_BASE_URL), '/');
    }

    public static function wapiInstanceId(): ?string
    {
        return Setting::get(self::KEY_WAPI_INSTANCE_ID);
    }

    public static function wapiToken(): ?string
    {
        return Setting::get(self::KEY_WAPI_TOKEN);
    }

    public static function proxybrBaseUrl(): string
    {
        return rtrim((string) Setting::get(self::KEY_PROXYBR_BASE_URL, ProxyhubConfig::DEFAULT_BASE_URL), '/');
    }

    public static function proxybrInstanceId(): ?string
    {
        return Setting::get(self::KEY_PROXYBR_INSTANCE_ID);
    }

    public static function proxybrToken(): ?string
    {
        return Setting::get(self::KEY_PROXYBR_TOKEN);
    }

    /**
     * Per-event enable map, e.g. ['welcome_registration' => true, ...].
     * Unknown/missing events default to enabled.
     *
     * @return array<string, bool>
     */
    public static function eventsMap(): array
    {
        $raw = Setting::get(self::KEY_EVENTS);

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    public static function eventEnabled(NotificationType $type): bool
    {
        return (bool) (self::eventsMap()[$type->value] ?? true);
    }
}
