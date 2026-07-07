<?php

namespace App\Services\Connection\WhatsApp;

use App\Models\Connection;
use App\Services\Connection\Meta\GraphApi;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read/update proxy for the WhatsApp Cloud API business profile.
 *
 * The profile (about / description / address / email / websites / vertical /
 * profile picture) lives on Meta's side, keyed by the phone_number_id. We proxy
 * get/update straight to the Graph API. All calls go through GraphApi::retry to
 * survive rate limits.
 *
 * Note: updating the profile picture requires Meta's resumable upload flow to
 * obtain a handle and is intentionally out of scope here — the picture is
 * read-only (profile_picture_url is returned by get()).
 */
class WhatsappBusinessProfileService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    private const FIELDS = 'about,address,description,email,profile_picture_url,websites,vertical';

    /** Fields Meta accepts on update. */
    private const UPDATABLE = ['about', 'address', 'description', 'email', 'websites', 'vertical'];

    /**
     * Fetch the business profile for the connection's phone number.
     *
     * @return array<string, mixed>
     */
    public function get(Connection $connection): array
    {
        [$phoneNumberId, $token] = $this->credentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->get(self::GRAPH_BASE . "/{$phoneNumberId}/whatsapp_business_profile", [
                'fields' => self::FIELDS,
            ]));

        if (!$response->successful()) {
            $this->fail('get', $response);
        }

        // Cloud API wraps the profile in data[0].
        return $response->json('data.0') ?? [];
    }

    /**
     * Update the profile's text fields. Returns the fresh profile.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Connection $connection, array $data): array
    {
        [$phoneNumberId, $token] = $this->credentials($connection);

        $payload = array_merge(
            ['messaging_product' => 'whatsapp'],
            array_filter(
                array_intersect_key($data, array_flip(self::UPDATABLE)),
                fn ($v) => $v !== null,
            ),
        );

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$phoneNumberId}/whatsapp_business_profile", $payload));

        if (!$response->successful()) {
            $this->fail('update', $response);
        }

        return $this->get($connection);
    }

    /**
     * @return array{0:string,1:string}  [phoneNumberId, accessToken]
     */
    private function credentials(Connection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $phoneNumberId = $credentials['phone_number_id'] ?? null;
        $token = $credentials['access_token'] ?? null;

        if (!$phoneNumberId || !$token) {
            throw new RuntimeException('WhatsApp connection is missing phone_number_id or access_token');
        }

        return [$phoneNumberId, $token];
    }

    private function fail(string $op, \Illuminate\Http\Client\Response $response): never
    {
        $message = $response->json('error.message') ?? "Failed to {$op} WhatsApp business profile";
        throw new RuntimeException($message, $response->status());
    }
}
