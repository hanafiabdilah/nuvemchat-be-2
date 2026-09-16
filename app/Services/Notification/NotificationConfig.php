<?php

namespace App\Services\Notification;

use App\Enums\Notification\NotificationType;
use App\Models\Setting;
use App\Services\Connection\Proxy\ApiwayConfig;

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
    public const KEY_TEMPLATES = 'notifications.templates';

    // Pingly — the platform's own public API (X-API-Key auth, /send-message with
    // { to, message }). This is NOT the raw w-api.app endpoint.
    public const KEY_PINGLY_BASE_URL = 'notifications.pingly.base_url';
    public const KEY_PINGLY_API_KEY = 'notifications.pingly.api_key';

    // W-API (Directly) — a W-API instance the operator configures directly.
    public const KEY_WAPI_BASE_URL = 'notifications.wapi.base_url';
    public const KEY_WAPI_INSTANCE_ID = 'notifications.wapi.instance_id';
    public const KEY_WAPI_TOKEN = 'notifications.wapi.token';

    // ProxyBR API (Directly) — an API Way instance (client-level credentials,
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
        return rtrim((string) Setting::get(self::KEY_PROXYBR_BASE_URL, ApiwayConfig::DEFAULT_BASE_URL), '/');
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

    /**
     * Per-event custom message templates, e.g. ['whatsapp_otp' => 'Your code is {{code}}'].
     *
     * @return array<string, string>
     */
    public static function templatesMap(): array
    {
        $raw = Setting::get(self::KEY_TEMPLATES);

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * The effective message body for an event, in the recipient's language: the
     * admin override when one exists for that language, otherwise the packaged
     * default. Both use the {{placeholder}} convention.
     */
    public static function template(NotificationType $type, ?string $locale = null): string
    {
        $locale = $locale ?: config('markets.default_locale');
        $custom = self::overridesFor($type)[$locale] ?? null;

        return is_string($custom) && trim($custom) !== ''
            ? $custom
            : $type->defaultTemplate($locale);
    }

    /**
     * The admin's overrides for one event, keyed by locale.
     *
     * ⚠️ A stored value that is a bare string is an override written before this
     * setting had languages — and therefore written in the platform's own. It is
     * claimed for that locale alone: handing a Portuguese customisation to an
     * Indonesian reader is not honouring the admin's intent, it is undoing the
     * translation they never knew existed.
     *
     * @return array<string, string>
     */
    private static function overridesFor(NotificationType $type): array
    {
        $entry = self::templatesMap()[$type->value] ?? null;

        if (is_string($entry)) {
            return [config('markets.default_locale') => $entry];
        }

        return is_array($entry) ? $entry : [];
    }

    /**
     * Narrow what the Back Office sent to what may actually be stored: known
     * events, known languages, non-blank bodies.
     *
     * Blank is dropped rather than saved because an empty override is how the
     * editor says "use the default" — storing it would pin the event to an
     * empty message instead. Accepts the legacy flat shape so a Back Office
     * build that predates languages can still save.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<string, string>>
     */
    public static function sanitizeTemplates(array $input): array
    {
        $events = array_column(NotificationType::cases(), 'value');
        $locales = array_keys(config('markets.locales'));
        $out = [];

        foreach ($input as $event => $bodies) {
            if (! in_array($event, $events, true)) {
                continue;
            }

            if (is_string($bodies)) {
                $bodies = [config('markets.default_locale') => $bodies];
            }

            if (! is_array($bodies)) {
                continue;
            }

            foreach ($bodies as $locale => $body) {
                if (! in_array($locale, $locales, true) || ! is_string($body) || trim($body) === '') {
                    continue;
                }

                $out[$event][$locale] = mb_substr($body, 0, 2000);
            }
        }

        return $out;
    }
}
