<?php

namespace App\Services\Contact\Photo\Resolvers;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Photo\PhotoResolver;
use App\Services\Contact\Photo\PhotoSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MessengerPhotoResolver implements PhotoResolver
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    public function resolve(Contact $contact, Connection $connection): ?PhotoSource
    {
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException('Messenger connection has no page access token');
        }

        // A PSID only exposes the basic profile fields, and only to the page
        // token that the person messaged.
        $response = Http::timeout(20)->get(self::GRAPH_BASE . '/' . $contact->external_id, [
            'fields' => 'profile_pic',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Messenger profile lookup failed: ' . ($response->json('error.message') ?? $response->status())
            );
        }

        $url = $response->json('profile_pic');

        return $url ? new PhotoSource(url: $url) : null;
    }
}
