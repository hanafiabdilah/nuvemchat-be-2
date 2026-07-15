<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Notification\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\Billing\MercadoPago\MercadoPagoConfig;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Connection\Meta\InstagramConfig;
use App\Services\Connection\Proxy\ProxyhubConfig;
use App\Services\Connection\WApi\WApiConfig;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\NotificationProviderFactory;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    /**
     * Return platform settings. Secrets are never sent in full — only a masked
     * preview and a "set" flag so the UI can show whether a value exists.
     */
    public function show()
    {
        $token = ProxyhubConfig::integratorToken();
        $mpAccess = MercadoPagoConfig::accessToken();
        $mpSecret = MercadoPagoConfig::webhookSecret();

        $igSecret = InstagramConfig::clientSecret();
        $igVerify = InstagramConfig::webhookVerifyToken();
        $fbSecret = FacebookConfig::appSecret();
        $fbVerify = FacebookConfig::webhookVerifyToken();
        $wapiToken = WApiConfig::managedToken();
        $aiToken = AiAgentHubConfig::adminToken();
        $notifPinglyKey = NotificationConfig::pinglyApiKey();
        $notifToken = NotificationConfig::wapiToken();
        $notifProxyToken = NotificationConfig::proxybrToken();

        return response()->json([
            'data' => [
                'proxyhub' => [
                    'base_url' => ProxyhubConfig::baseUrl(),
                    'integrator_token_set' => ! empty($token),
                    'integrator_token_preview' => $this->mask($token),
                ],
                'mercadopago' => [
                    // Public key is meant to be exposed (frontend Bricks).
                    'public_key' => MercadoPagoConfig::publicKey(),
                    'back_url' => MercadoPagoConfig::backUrl(),
                    'access_token_set' => ! empty($mpAccess),
                    'access_token_preview' => $this->mask($mpAccess),
                    'webhook_secret_set' => ! empty($mpSecret),
                    'webhook_secret_preview' => $this->mask($mpSecret),
                ],
                'instagram' => [
                    'client_id' => InstagramConfig::clientId(),
                    'redirect_uri' => InstagramConfig::redirectUri(),
                    'client_secret_set' => ! empty($igSecret),
                    'client_secret_preview' => $this->mask($igSecret),
                    'webhook_verify_token_set' => ! empty($igVerify),
                    'webhook_verify_token_preview' => $this->mask($igVerify),
                ],
                'facebook' => [
                    'app_id' => FacebookConfig::appId(),
                    'config_id' => FacebookConfig::configId(),
                    'redirect_uri' => FacebookConfig::redirectUri(),
                    'app_secret_set' => ! empty($fbSecret),
                    'app_secret_preview' => $this->mask($fbSecret),
                    'webhook_verify_token_set' => ! empty($fbVerify),
                    'webhook_verify_token_preview' => $this->mask($fbVerify),
                ],
                'wapi' => [
                    'managed_token_set' => ! empty($wapiToken),
                    'managed_token_preview' => $this->mask($wapiToken),
                ],
                'ai_agent_hub' => [
                    'base_url' => AiAgentHubConfig::baseUrl(),
                    'admin_token_set' => ! empty($aiToken),
                    'admin_token_preview' => $this->mask($aiToken),
                ],
                'notifications' => [
                    'enabled' => NotificationConfig::enabled(),
                    'provider' => NotificationConfig::provider(),
                    'providers' => app(NotificationProviderFactory::class)->available(),
                    'pingly' => [
                        'base_url' => NotificationConfig::pinglyBaseUrl(),
                        'api_key_set' => ! empty($notifPinglyKey),
                        'api_key_preview' => $this->mask($notifPinglyKey),
                    ],
                    'wapi' => [
                        'base_url' => NotificationConfig::wapiBaseUrl(),
                        'instance_id' => NotificationConfig::wapiInstanceId(),
                        'token_set' => ! empty($notifToken),
                        'token_preview' => $this->mask($notifToken),
                    ],
                    'proxybr' => [
                        'base_url' => NotificationConfig::proxybrBaseUrl(),
                        'instance_id' => NotificationConfig::proxybrInstanceId(),
                        'token_set' => ! empty($notifProxyToken),
                        'token_preview' => $this->mask($notifProxyToken),
                    ],
                    // Event catalog (label + default template + placeholders + required),
                    // current per-event toggles, and per-event message overrides.
                    'event_types' => NotificationType::catalog(),
                    'events' => NotificationConfig::eventsMap(),
                    'templates' => NotificationConfig::templatesMap(),
                ],
            ],
        ]);
    }

    /**
     * Update platform settings. The integrator token is only changed when a new
     * non-empty value is supplied, so saving the base URL alone won't wipe it.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'proxyhub' => ['sometimes', 'array'],
            'proxyhub.base_url' => ['required_with:proxyhub', 'url', 'max:255'],
            'proxyhub.integrator_token' => ['nullable', 'string', 'max:255'],

            'mercadopago' => ['sometimes', 'array'],
            'mercadopago.public_key' => ['nullable', 'string', 'max:255'],
            'mercadopago.back_url' => ['nullable', 'url', 'max:255'],
            'mercadopago.access_token' => ['nullable', 'string', 'max:512'],
            'mercadopago.webhook_secret' => ['nullable', 'string', 'max:255'],

            'instagram' => ['sometimes', 'array'],
            'instagram.client_id' => ['nullable', 'string', 'max:255'],
            'instagram.redirect_uri' => ['nullable', 'url', 'max:255'],
            'instagram.client_secret' => ['nullable', 'string', 'max:512'],
            'instagram.webhook_verify_token' => ['nullable', 'string', 'max:255'],

            'facebook' => ['sometimes', 'array'],
            'facebook.app_id' => ['nullable', 'string', 'max:255'],
            'facebook.config_id' => ['nullable', 'string', 'max:255'],
            'facebook.redirect_uri' => ['nullable', 'url', 'max:255'],
            'facebook.app_secret' => ['nullable', 'string', 'max:512'],
            'facebook.webhook_verify_token' => ['nullable', 'string', 'max:255'],

            'wapi' => ['sometimes', 'array'],
            'wapi.managed_token' => ['nullable', 'string', 'max:1024'],

            'ai_agent_hub' => ['sometimes', 'array'],
            'ai_agent_hub.base_url' => ['nullable', 'url', 'max:255'],
            'ai_agent_hub.admin_token' => ['nullable', 'string', 'max:512'],

            'notifications' => ['sometimes', 'array'],
            'notifications.enabled' => ['sometimes', 'boolean'],
            'notifications.provider' => ['sometimes', 'string', 'max:50'],
            'notifications.pingly' => ['sometimes', 'array'],
            'notifications.pingly.base_url' => ['nullable', 'url', 'max:255'],
            'notifications.pingly.api_key' => ['nullable', 'string', 'max:1024'],
            'notifications.wapi' => ['sometimes', 'array'],
            'notifications.wapi.base_url' => ['nullable', 'url', 'max:255'],
            'notifications.wapi.instance_id' => ['nullable', 'string', 'max:255'],
            'notifications.wapi.token' => ['nullable', 'string', 'max:1024'],
            'notifications.proxybr' => ['sometimes', 'array'],
            'notifications.proxybr.base_url' => ['nullable', 'url', 'max:255'],
            'notifications.proxybr.instance_id' => ['nullable', 'string', 'max:255'],
            'notifications.proxybr.token' => ['nullable', 'string', 'max:1024'],
            'notifications.events' => ['sometimes', 'array'],
            'notifications.events.*' => ['boolean'],
            'notifications.templates' => ['sometimes', 'array'],
            'notifications.templates.*' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->has('proxyhub')) {
            Setting::set(ProxyhubConfig::KEY_BASE_URL, rtrim($validated['proxyhub']['base_url'], '/'));

            if (! empty($validated['proxyhub']['integrator_token'])) {
                Setting::set(ProxyhubConfig::KEY_INTEGRATOR_TOKEN, $validated['proxyhub']['integrator_token']);
            }
        }

        if ($request->has('mercadopago')) {
            $mp = $validated['mercadopago'];

            // Public values: stored as-is (not secret).
            Setting::set(MercadoPagoConfig::KEY_PUBLIC_KEY, $mp['public_key'] ?? null);
            Setting::set(MercadoPagoConfig::KEY_BACK_URL, $mp['back_url'] ?? null);

            // Secrets: only replaced when a new value is supplied.
            if (! empty($mp['access_token'])) {
                Setting::set(MercadoPagoConfig::KEY_ACCESS_TOKEN, $mp['access_token']);
            }
            if (! empty($mp['webhook_secret'])) {
                Setting::set(MercadoPagoConfig::KEY_WEBHOOK_SECRET, $mp['webhook_secret']);
            }
        }

        if ($request->has('instagram')) {
            $ig = $validated['instagram'];

            // Public values: stored as-is.
            Setting::set(InstagramConfig::KEY_CLIENT_ID, $ig['client_id'] ?? null);
            Setting::set(InstagramConfig::KEY_REDIRECT_URI, $ig['redirect_uri'] ?? null);

            // Secrets: only replaced when a new value is supplied.
            if (! empty($ig['client_secret'])) {
                Setting::set(InstagramConfig::KEY_CLIENT_SECRET, $ig['client_secret']);
            }
            if (! empty($ig['webhook_verify_token'])) {
                Setting::set(InstagramConfig::KEY_WEBHOOK_VERIFY_TOKEN, $ig['webhook_verify_token']);
            }
        }

        if ($request->has('facebook')) {
            $fb = $validated['facebook'];

            // Public values: stored as-is.
            Setting::set(FacebookConfig::KEY_APP_ID, $fb['app_id'] ?? null);
            Setting::set(FacebookConfig::KEY_CONFIG_ID, $fb['config_id'] ?? null);
            Setting::set(FacebookConfig::KEY_REDIRECT_URI, $fb['redirect_uri'] ?? null);

            // Secrets: only replaced when a new value is supplied.
            if (! empty($fb['app_secret'])) {
                Setting::set(FacebookConfig::KEY_APP_SECRET, $fb['app_secret']);
            }
            if (! empty($fb['webhook_verify_token'])) {
                Setting::set(FacebookConfig::KEY_WEBHOOK_VERIFY_TOKEN, $fb['webhook_verify_token']);
            }
        }

        if ($request->has('wapi')) {
            // Secret: only replaced when a new value is supplied.
            if (! empty($validated['wapi']['managed_token'])) {
                Setting::set(WApiConfig::KEY_MANAGED_TOKEN, $validated['wapi']['managed_token']);
            }
        }

        if ($request->has('ai_agent_hub')) {
            $ai = $validated['ai_agent_hub'];

            // Base URL: stored as-is (falls back to the default when empty).
            Setting::set(AiAgentHubConfig::KEY_BASE_URL, $ai['base_url'] ?? null);

            // Secret: only replaced when a new value is supplied.
            if (! empty($ai['admin_token'])) {
                Setting::set(AiAgentHubConfig::KEY_ADMIN_TOKEN, $ai['admin_token']);
            }
        }

        if ($request->has('notifications')) {
            $n = $validated['notifications'];

            if (array_key_exists('enabled', $n)) {
                Setting::set(NotificationConfig::KEY_ENABLED, $n['enabled'] ? '1' : '0');
            }
            if (! empty($n['provider'])) {
                Setting::set(NotificationConfig::KEY_PROVIDER, $n['provider']);
            }
            if (isset($n['pingly'])) {
                Setting::set(NotificationConfig::KEY_PINGLY_BASE_URL, $n['pingly']['base_url'] ?? null);
                if (! empty($n['pingly']['api_key'])) {
                    Setting::set(NotificationConfig::KEY_PINGLY_API_KEY, $n['pingly']['api_key']);
                }
            }
            if (isset($n['wapi'])) {
                // Public values: stored as-is.
                Setting::set(NotificationConfig::KEY_WAPI_BASE_URL, $n['wapi']['base_url'] ?? null);
                Setting::set(NotificationConfig::KEY_WAPI_INSTANCE_ID, $n['wapi']['instance_id'] ?? null);

                // Secret: only replaced when a new value is supplied.
                if (! empty($n['wapi']['token'])) {
                    Setting::set(NotificationConfig::KEY_WAPI_TOKEN, $n['wapi']['token']);
                }
            }
            if (isset($n['proxybr'])) {
                Setting::set(NotificationConfig::KEY_PROXYBR_BASE_URL, $n['proxybr']['base_url'] ?? null);
                Setting::set(NotificationConfig::KEY_PROXYBR_INSTANCE_ID, $n['proxybr']['instance_id'] ?? null);
                if (! empty($n['proxybr']['token'])) {
                    Setting::set(NotificationConfig::KEY_PROXYBR_TOKEN, $n['proxybr']['token']);
                }
            }
            if (array_key_exists('templates', $n)) {
                // Store only non-empty overrides; blank falls back to the enum default.
                $templates = array_filter(
                    $n['templates'],
                    fn ($v) => is_string($v) && trim($v) !== '',
                );
                Setting::set(NotificationConfig::KEY_TEMPLATES, json_encode($templates));
            }

            if (array_key_exists('events', $n)) {
                Setting::set(NotificationConfig::KEY_EVENTS, json_encode($n['events']));
            }
        }

        AuditLog::record('settings.update', 'Updated platform settings');

        return $this->show();
    }

    private function mask(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $len = strlen($value);

        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', max(4, $len - 4)) . substr($value, -4);
    }
}
