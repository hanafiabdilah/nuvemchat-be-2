<?php

namespace App\Services\Contact\Photo\Resolvers;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Photo\PhotoResolver;
use App\Services\Contact\Photo\PhotoSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramPhotoResolver implements PhotoResolver
{
    public function resolve(Contact $contact, Connection $connection): ?PhotoSource
    {
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException('Instagram connection has no access token');
        }

        $response = Http::timeout(20)->get("https://graph.instagram.com/v25.0/{$contact->external_id}", [
            'fields' => 'profile_pic',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Instagram profile lookup failed: ' . ($response->json('error.message') ?? $response->status())
            );
        }

        $url = $response->json('profile_pic');

        return $url ? new PhotoSource(url: $url) : null;
    }
}
