<?php

namespace App\Services\Contact\Photo\Resolvers;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Connection\Proxy\ApiwayConfig;
use App\Services\Contact\Photo\PhotoResolver;
use App\Services\Contact\Photo\PhotoSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The core's /v1/contacts/profile-picture takes "número em formato
 * internacional ou JID", so one call covers both a person's phone and a
 * group's @g.us JID — group subjects arrive via GroupInfo events but the
 * picture never does, and this is the only way to read it.
 *
 * `data` is whatever the WhatsApp node returned: sometimes the URL as a bare
 * string, sometimes whatsmeow's ProfilePictureInfo object.
 *
 * NB: this endpoint is documented in the API Way collection but has not been
 * exercised against the live core — the neighbouring /v1/group/* family was
 * found to 404 there. Hence every non-2xx (404 included) is reported as a
 * failure rather than "this chat has no picture": a routing 404 must not be
 * allowed to delete photos. A chat that genuinely has none comes back 200
 * with an empty `data`, which extractUrl() reads as null.
 */
class WhatsappApiwayPhotoResolver implements PhotoResolver
{
    public function resolve(Contact $contact, Connection $connection): ?PhotoSource
    {
        $instanceId = $connection->credentials['instance_id'] ?? null;
        $token = $connection->credentials['token'] ?? null;

        if (! $instanceId || ! $token) {
            throw new RuntimeException('API Way connection has no linked instance');
        }

        $response = Http::timeout(20)
            ->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get(ApiwayConfig::baseUrl() . '/v1/contacts/profile-picture', [
                'instanceId' => $instanceId,
                'phoneNumber' => $contact->external_id,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('API Way profile-picture failed: ' . $response->status());
        }

        $url = $this->extractUrl($response->json('data'));

        return $url ? new PhotoSource(url: $url) : null;
    }

    private function extractUrl(mixed $data): ?string
    {
        if (is_string($data)) {
            return $data !== '' ? $data : null;
        }

        if (is_array($data)) {
            foreach (['url', 'URL', 'profilePictureUrl', 'pictureUrl'] as $key) {
                if (! empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }

        return null;
    }
}
