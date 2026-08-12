<?php

namespace App\Services\Connection\WhatsApp;

use App\Models\Connection;
use App\Services\Connection\Meta\FacebookConfig;
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
                // parameter_format tells the send form whether a template's
                // variables are numbered or named — the two take different
                // parameter shapes on the Cloud API.
                'fields' => 'name,status,category,language,components,quality_score,parameter_format',
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
     * @param  array{name:string,category:string,language:string,components:array,allow_category_change?:bool,parameter_format?:string}  $data
     * @return array<string, mixed>
     */
    public function create(Connection $connection, array $data): array
    {
        [$wabaId, $token] = $this->credentials($connection);

        $payload = [
            'name' => $data['name'],
            'category' => $data['category'],
            'language' => $data['language'],
            'components' => $data['components'],
        ];

        // Sent only when asked for: Meta reads the absent flag as "keep my
        // category and reject if you disagree", which is the stricter default.
        if (!empty($data['allow_category_change'])) {
            $payload['allow_category_change'] = true;
        }

        // Omitted for POSITIONAL, which is what Meta assumes anyway — sending
        // it would only pin older templates to a format they never declared.
        if (($data['parameter_format'] ?? null) === 'NAMED') {
            $payload['parameter_format'] = 'NAMED';
        }

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$wabaId}/message_templates", $payload));

        if (!$response->successful()) {
            $this->fail('create', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * Upload a sample media asset and return the opaque handle a template's
     * media header is created with.
     *
     * Templates cannot reference a media id or a URL at creation time — Meta
     * wants the bytes up front, through the Resumable Upload API, and hands
     * back a handle that stands for them. Two calls: open a session against the
     * Meta app, then push the whole file at offset 0 (our ceiling is well under
     * the point where resuming would earn its keep).
     *
     * Note the second call authenticates with `OAuth`, not `Bearer` — the
     * upload host is particular about it.
     */
    public function uploadHandle(Connection $connection, string $contents, string $mimeType, string $fileName): string
    {
        [, $token] = $this->credentials($connection);
        $appId = FacebookConfig::appId();

        if (!$appId) {
            throw new RuntimeException(
                'Facebook App ID is not configured — set it in Back Office → Integrations before uploading template media.'
            );
        }

        $session = GraphApi::retry(fn () => Http::withToken($token)
            ->post(self::GRAPH_BASE . "/{$appId}/uploads", [
                'file_name' => $fileName,
                'file_length' => strlen($contents),
                'file_type' => $mimeType,
            ]));

        if (!$session->successful()) {
            $this->fail('open an upload session for', $session);
        }

        $sessionId = $session->json('id');

        if (!$sessionId) {
            throw new RuntimeException('WhatsApp upload session did not return an id');
        }

        $upload = GraphApi::retry(fn () => Http::withHeaders([
            'Authorization' => 'OAuth ' . $token,
            'file_offset' => '0',
            'Content-Type' => 'application/octet-stream',
        ])->withBody($contents, 'application/octet-stream')
            ->post(self::GRAPH_BASE . "/{$sessionId}"));

        if (!$upload->successful()) {
            $this->fail('upload template media for', $upload);
        }

        $handle = $upload->json('h');

        if (!$handle) {
            throw new RuntimeException('WhatsApp upload did not return a media handle');
        }

        return $handle;
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
