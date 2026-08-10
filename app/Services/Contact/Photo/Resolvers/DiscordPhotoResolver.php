<?php

namespace App\Services\Contact\Photo\Resolvers;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Photo\PhotoResolver;
use App\Services\Contact\Photo\PhotoSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Discord avatars are a CDN path built from the user's current avatar hash, so
 * the hash has to be re-read from the API to notice a change — the one that
 * arrived on the original MESSAGE_CREATE payload goes stale silently.
 */
class DiscordPhotoResolver implements PhotoResolver
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function resolve(Contact $contact, Connection $connection): ?PhotoSource
    {
        $token = $connection->credentials['token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Discord connection has no bot token');
        }

        $response = Http::timeout(20)
            ->withHeaders(['Authorization' => 'Bot ' . $token])
            ->get(self::API_BASE . '/users/' . $contact->external_id);

        if ($response->failed()) {
            throw new RuntimeException('Discord GET /users failed: ' . $response->status());
        }

        $hash = $response->json('avatar');

        if (! $hash) {
            // Default (auto-generated) avatar — nothing worth storing; the UI
            // initials fallback is a better rendering of it anyway.
            return null;
        }

        $extension = str_starts_with($hash, 'a_') ? 'gif' : 'png';

        return new PhotoSource(
            url: "https://cdn.discordapp.com/avatars/{$contact->external_id}/{$hash}.{$extension}?size=256",
            extension: $extension,
        );
    }
}
