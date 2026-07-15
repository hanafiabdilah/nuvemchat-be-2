<?php

namespace App\Services\Connection\WhatsApp;

use App\Models\Connection;
use App\Services\Connection\Meta\GraphApi;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CRUD proxy for WhatsApp Cloud API message templates.
 *
 * Templates live on Meta's side, scoped to the WABA (business_account_id).
 * We proxy list/create/delete straight to the Graph API rather than mirroring
 * them locally, so the dashboard always reflects Meta's current approval state.
 * All calls go through GraphApi::retry to survive rate limits.
 */
class WhatsappTemplateService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    /**
     * List templates for the connection's WABA.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Connection $connection): array
    {
        [$wabaId, $token] = $this->credentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->get(self::GRAPH_BASE . "/{$wabaId}/message_templates", [
                'fields' => 'name,status,category,language,components,quality_score',
                'limit' => 200,
            ]));

        if (!$response->successful()) {
            $this->fail('list', $response);
        }

        return $response->json('data') ?? [];
    }

    /**
     * Create a template on the WABA. Meta returns it in a PENDING review state.
     *
     * @param  array{name:string,category:string,language:string,components:array}  $data
     * @return array<string, mixed>
     */
    public function create(Connection $connection, array $data): array
    {
        [$wabaId, $token] = $this->credentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$wabaId}/message_templates", [
                'name' => $data['name'],
                'category' => $data['category'],
                'language' => $data['language'],
                'components' => $data['components'],
            ]));

        if (!$response->successful()) {
            $this->fail('create', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * Delete a template by name (removes every language variant of that name).
     */
    public function delete(Connection $connection, string $name): void
    {
        [$wabaId, $token] = $this->credentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->delete(self::GRAPH_BASE . "/{$wabaId}/message_templates", [
                'name' => $name,
            ]));

        if (!$response->successful()) {
            $this->fail('delete', $response);
        }
    }

    /**
     * @return array{0:string,1:string}  [wabaId, accessToken]
     */
    private function credentials(Connection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $wabaId = $credentials['business_account_id'] ?? null;
        $token = $credentials['access_token'] ?? null;

        if (!$wabaId || !$token) {
            throw new RuntimeException('WhatsApp connection is missing business_account_id or access_token');
        }

        return [$wabaId, $token];
    }

    private function fail(string $op, \Illuminate\Http\Client\Response $response): never
    {
        $message = $response->json('error.message') ?? "Failed to {$op} WhatsApp template";
        throw new RuntimeException($message, $response->status());
    }
}
