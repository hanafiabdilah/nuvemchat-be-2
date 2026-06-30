<?php

namespace App\Services\AiAgentHub;

use App\Models\Setting;

/**
 * Single source of truth for AI Agent Hub credentials. Stored in the
 * `settings` table (DB-only), managed by super-admin in the Back Office →
 * Integrations — not in .env. The base URL keeps a sensible default.
 */
class AiAgentHubConfig
{
    public const KEY_BASE_URL = 'ai_agent_hub.base_url';
    public const KEY_ADMIN_TOKEN = 'ai_agent_hub.admin_token';

    /** Default used until an admin overrides it in the database. */
    public const DEFAULT_BASE_URL = 'https://api-ia.ipbr.pro/v1';

    public static function baseUrl(): string
    {
        $url = Setting::get(self::KEY_BASE_URL) ?: self::DEFAULT_BASE_URL;

        return rtrim($url, '/');
    }

    public static function adminToken(): ?string
    {
        return Setting::get(self::KEY_ADMIN_TOKEN);
    }
}
