<?php

namespace App\Services\Youtube;

use App\Models\Setting;

/**
 * The platform's Google OAuth client, the one every workspace connects its
 * YouTube channels through.
 *
 * Stored in the `settings` table and edited in Back Office → Integrations, like
 * every other app-level credential. The redirect URI is deliberately not a
 * setting: it is our own route, built on PLATFORM_URL, and the only thing an
 * operator has to do with it is copy it into the Google Cloud console — a value
 * retyped from memory is a value typed wrong.
 */
class YoutubeConfig
{
    public const KEY_CLIENT_ID = 'youtube.client_id';
    public const KEY_CLIENT_SECRET = 'youtube.client_secret';

    /**
     * One scope covers everything the feature does: upload (videos.insert
     * accepts it), edit and delete videos, read the channel, and every comment
     * call — the moderation ones require it specifically. Asking for less
     * would leave comment moderation unreachable; asking for more (the
     * partner scopes) would put a restricted scope on the consent screen for
     * nothing.
     */
    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    public static function clientId(): ?string
    {
        return Setting::get(self::KEY_CLIENT_ID) ?: null;
    }

    public static function clientSecret(): ?string
    {
        return Setting::get(self::KEY_CLIENT_SECRET) ?: null;
    }

    public static function redirectUri(): string
    {
        return route('oauth.youtube.callback');
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== null && self::clientSecret() !== null;
    }
}
