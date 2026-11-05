<?php

namespace App\Services\Connection\TikTok;

use App\Models\Setting;

/**
 * Single source of truth for the TikTok for Business app: its credentials and
 * which of TikTok's permissions it holds. Stored in the `settings` table
 * (DB-only), managed by super-admin in the Back Office → Integrations — not in
 * .env.
 */
class TikTokConfig
{
    /** TikTok Business API host (OAuth + Business Messaging endpoints). */
    public const BASE_URL = 'https://business-api.tiktok.com';

    public const KEY_APP_ID = 'tiktok.app_id';
    public const KEY_APP_SECRET = 'tiktok.app_secret';
    public const KEY_REDIRECT_URI = 'tiktok.redirect_uri';

    /**
     * Whether Connect also asks for the post & comment permissions.
     *
     * A switch rather than a constant because those permissions belong to the
     * Accounts API, which TikTok grants to a developer app separately from
     * Business Messaging. Asking for a scope the app does not hold breaks the
     * consent screen for everyone — including people who only wanted the
     * inbox — so it is turned on in the Back Office once TikTok has approved it.
     */
    public const KEY_POSTS_ENABLED = 'tiktok.posts_enabled';

    /**
     * Whether Connect also asks for the direct message permissions.
     *
     * The Business Messaging scopes are not in the app's scope picker: TikTok
     * grants them after its data security & privacy review, separately from
     * the Accounts API that carries posts and comments. Until then asking for
     * them breaks the consent screen for every connection, so a workspace that
     * only wants posts could not connect at all. Off until TikTok says yes.
     */
    public const KEY_MESSAGING_ENABLED = 'tiktok.messaging_enabled';

    /**
     * The URL prefix TikTok has verified as ours (JSON: url, status,
     * file_name, verified_at). Videos and photos are only published from a
     * verified address — TikTok fetches them, and refuses unverified hosts.
     */
    public const KEY_URL_PROPERTY = 'tiktok.url_property';

    public static function appId(): ?string
    {
        return Setting::get(self::KEY_APP_ID);
    }

    public static function appSecret(): ?string
    {
        return Setting::get(self::KEY_APP_SECRET);
    }

    public static function redirectUri(): ?string
    {
        return Setting::get(self::KEY_REDIRECT_URI);
    }

    public static function postsEnabled(): bool
    {
        return (bool) Setting::get(self::KEY_POSTS_ENABLED);
    }

    public static function messagingEnabled(): bool
    {
        return (bool) Setting::get(self::KEY_MESSAGING_ENABLED);
    }

    /** @return array{url?: string, status?: int, file_name?: string, verified_at?: string}|null */
    public static function urlProperty(): ?array
    {
        $raw = Setting::get(self::KEY_URL_PROPERTY);
        $value = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($value) ? $value : null;
    }

    /** The verified prefix, or null when TikTok has not confirmed one. */
    public static function verifiedPrefix(): ?string
    {
        $property = self::urlProperty();

        return ($property['status'] ?? null) === 1 && ! empty($property['url']) ? $property['url'] : null;
    }
}
