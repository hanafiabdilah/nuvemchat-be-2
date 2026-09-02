<?php

namespace App\Services\AiAgentHub;

use App\Models\Setting;

/**
 * Single source of truth for AI Agent Hub credentials. Stored in the
 * `settings` table (DB-only), managed by super-admin in the Back Office →
 * Integrations — not in .env. The base URL keeps a sensible default.
 *
 * ── Two senses of "tenant", never to be confused ──
 *
 * **Our tenant at the hub** is the whole platform: Pingly is one tenant of
 * api-ia.ipbr.pro, and the token below is that tenant's key. It authenticates
 * every hub call this application makes, for every workspace.
 *
 * **A tenant in our platform** is a customer workspace (`tenants` table). It is
 * a local scope, not a hub identity — the hub has never heard of it and must
 * not: registering one would need the hub's *admin* token, which belongs to
 * whoever operates the hub and is not ours to hold.
 */
class AiAgentHubConfig
{
    public const KEY_BASE_URL = 'ai_agent_hub.base_url';

    /**
     * Our own hub tenant key. Sent as `Authorization: Bearer` on every call.
     */
    public const KEY_TENANT_TOKEN = 'ai_agent_hub.tenant_token';

    /**
     * The name this same value was stored under while the platform mistakenly
     * believed it held an admin token. Kept as a read fallback so the key an
     * operator already pasted keeps working without being re-entered.
     *
     * @deprecated Use {@see KEY_TENANT_TOKEN}.
     */
    public const KEY_ADMIN_TOKEN = 'ai_agent_hub.admin_token';

    /** Default used until an admin overrides it in the database. */
    public const DEFAULT_BASE_URL = 'https://api-ia.ipbr.pro/v1';

    public static function baseUrl(): string
    {
        $url = Setting::get(self::KEY_BASE_URL) ?: self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    /**
     * The platform's own key at the hub — the only credential this application
     * has, and the one behind every request it sends there.
     */
    public static function tenantToken(): ?string
    {
        return Setting::get(self::KEY_TENANT_TOKEN) ?: Setting::get(self::KEY_ADMIN_TOKEN);
    }

    /**
     * @deprecated Misnomer: the value under this key is the platform's tenant
     * token, not an admin token. Use {@see tenantToken()}.
     */
    public static function adminToken(): ?string
    {
        return self::tenantToken();
    }
}
