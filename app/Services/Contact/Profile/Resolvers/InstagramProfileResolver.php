<?php

namespace App\Services\Contact\Profile\Resolvers;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Profile\ContactProfile;
use App\Services\Contact\Profile\ProfileResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Instagram's User Profile API, the only place a scoped id (IGSID) can be
 * turned into something a human recognises.
 *
 * It answers for people who are in a conversation with the account — which is
 * why this is worth retrying rather than deciding once: an id the API refused
 * while the business was writing first becomes readable the moment that person
 * replies.
 */
class InstagramProfileResolver implements ProfileResolver
{
    public function resolve(Contact $contact, Connection $connection): ?ContactProfile
    {
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException('Instagram connection has no access token');
        }

        $response = Http::timeout(20)->get("https://graph.instagram.com/v25.0/{$contact->external_id}", [
            'fields' => 'name,username',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Instagram profile lookup failed: ' . ($response->json('error.message') ?? $response->status())
            );
        }

        $username = $response->json('username');

        // The display name is optional on Instagram; the username never is, so
        // it doubles as the name when the account has no other label.
        return new ContactProfile(
            name: $response->json('name') ?: $username,
            username: $username,
        );
    }
}
