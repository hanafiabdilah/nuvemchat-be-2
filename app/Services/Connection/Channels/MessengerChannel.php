<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MessengerChannel implements ChannelInterface
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    /**
     * Two entry points share this method:
     * - The OAuth callback (single Page): passes the full credential set,
     *   including the page access_token.
     * - The page picker (multiple Pages): passes only page_id; the Page is
     *   resolved from the pending_pages list stored by the OAuth callback and
     *   its token fetched with the stored user token.
     */
    public function connect(Connection $connection, array $data): void
    {
        validator($data, [
            'page_id' => ['required', 'string'],
            'page_name' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'user_access_token' => ['nullable', 'string'],
            'fb_user_id' => ['nullable', 'string'],
        ])->validate();

        $existingCredentials = $connection->credentials ?? [];

        $pageId = (string) $data['page_id'];
        $pageName = $data['page_name'] ?? null;
        $pageToken = $data['access_token'] ?? null;
        $userToken = $data['user_access_token'] ?? ($existingCredentials['user_access_token'] ?? null);
        $fbUserId = $data['fb_user_id'] ?? ($existingCredentials['fb_user_id'] ?? null);

        // Picker path: the page must be one the OAuth callback listed for this
        // connection — never accept an arbitrary page_id from the client.
        if (!$pageToken) {
            $pendingPages = $existingCredentials['pending_pages'] ?? [];
            $pending = collect($pendingPages)->firstWhere('id', $pageId);

            if (!$pending) {
                throw ValidationException::withMessages(['page_id' => 'This Page was not authorized during the Facebook login.']);
            }

            if (!$userToken) {
                throw ValidationException::withMessages(['page_id' => 'The Facebook authorization expired. Please reconnect.']);
            }

            $pageName = $pageName ?? ($pending['name'] ?? null);
            // Prefer the token captured during login. Asking Graph for it again
            // fails whenever the app cannot read the Page node directly, so the
            // re-fetch is only a fallback — for rows stored before tokens were
            // kept, and for the single-Page path that never had a picker.
            $pageToken = $pending['access_token'] ?? $this->fetchPageToken($pageId, $userToken);
        }

        if (Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::Messenger)
            ->where('credentials->page_id', $pageId)
            ->exists()) {
            throw ValidationException::withMessages(['page_id' => 'This Facebook Page is already connected.']);
        }

        try {
            // Verify the page token and pick up the canonical Page name.
            $response = Http::get(self::GRAPH_BASE . '/me', [
                'fields' => 'id,name',
                'access_token' => $pageToken,
            ]);

            // `name` on a Page node needs pages_read_engagement, and Graph
            // refuses the whole request over one disallowed field. The name is
            // a nicety — the login already told us what the Page is called —
            // so drop it and keep the part that matters: proving the token
            // works. Without this the connect fails on a perfectly good token.
            if (!$response->successful()) {
                $response = Http::get(self::GRAPH_BASE . '/me', [
                    'fields' => 'id',
                    'access_token' => $pageToken,
                ]);
            }

            if (!$response->successful()) {
                Log::error('Invalid Messenger page access token', [
                    'connection_id' => $connection->id,
                    'response' => $response->json(),
                ]);
                throw new Exception('Invalid Facebook Page access token.');
            }

            $pageInfo = $response->json();

            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'access_token' => $pageToken,
                    'page_id' => $pageId,
                    'page_name' => $pageInfo['name'] ?? $pageName,
                    'user_access_token' => $userToken,
                    // App-scoped user id from the OAuth callback — used to match
                    // Meta deauth/data-deletion signed_requests back to this row.
                    'fb_user_id' => $fbUserId,
                ],
            ]);

            $this->subscribeWebhook($connection);
        } catch (ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('Failed to connect Messenger page', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
            throw new Exception('An error occurred while connecting to Messenger: ' . $th->getMessage());
        }
    }

    /**
     * Pages do support server-side cleanup (unlike Instagram Login): removing
     * the app's webhook subscription from the Page stops event delivery. We do
     * NOT revoke the user's app authorization (DELETE /{user}/permissions) —
     * the same Facebook user may hold WhatsApp Official connections whose
     * token would die with it.
     */
    public function disconnect(Connection $connection): void
    {
        $pageId = $connection->credentials['page_id'] ?? null;
        $pageToken = $connection->credentials['access_token'] ?? null;

        if ($pageId && $pageToken) {
            try {
                $response = Http::withToken($pageToken)
                    ->delete(self::GRAPH_BASE . "/{$pageId}/subscribed_apps");

                if (!$response->successful()) {
                    Log::warning('Messenger disconnect: failed to unsubscribe app from page', [
                        'connection_id' => $connection->id,
                        'page_id' => $pageId,
                        'response' => $response->json(),
                    ]);
                }
            } catch (\Throwable $th) {
                Log::warning('Messenger disconnect: error unsubscribing app from page', [
                    'connection_id' => $connection->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        $connection->update([
            'status' => Status::Inactive,
            'credentials' => null,
        ]);
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $response = Http::get(self::GRAPH_BASE . '/me', [
                'fields' => 'id,name',
                'access_token' => $connection->credentials['access_token'] ?? null,
            ]);

            if ($response->successful()) {
                $connection->update([
                    'status' => Status::Active,
                ]);
            } else {
                $connection->update([
                    'status' => Status::Inactive,
                ]);

                throw new ConnectionException('Invalid Facebook Page access token. Please reconnect your Page.', 400);
            }
        } catch (ConnectionException $th) {
            throw $th;
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            throw new ConnectionException('An error occurred while checking the Messenger connection. Please try again later.', 500);
        }
    }

    /**
     * Exchange the long-lived user token for the Page's access token. Page
     * tokens obtained from a long-lived user token do not expire.
     */
    private function fetchPageToken(string $pageId, string $userToken): string
    {
        $response = Http::get(self::GRAPH_BASE . "/{$pageId}", [
            'fields' => 'access_token',
            'access_token' => $userToken,
        ]);

        if (!$response->successful() || empty($response->json()['access_token'])) {
            Log::error('Failed to fetch Messenger page access token', [
                'page_id' => $pageId,
                'response' => $response->json(),
            ]);
            throw new Exception('Failed to obtain the Page access token: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
        }

        return $response->json()['access_token'];
    }

    private function subscribeWebhook(Connection $connection): void
    {
        $pageId = $connection->credentials['page_id'] ?? null;
        $pageToken = $connection->credentials['access_token'] ?? null;

        if (!$pageId || !$pageToken) {
            throw new Exception('Missing page_id or access_token for webhook subscription.');
        }

        // POST /{page-id}/subscribed_apps — subscribes THIS app to the Page's
        // Messenger events (delivered to /webhook/facebook).
        $response = Http::post(self::GRAPH_BASE . "/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'messages,message_reads,message_deliveries,message_reactions,message_echoes',
            'access_token' => $pageToken,
        ]);

        if ($response->successful()) {
            Log::info('Successfully subscribed to Messenger webhooks', [
                'connection_id' => $connection->id,
                'page_id' => $pageId,
                'response' => $response->json(),
            ]);
        } else {
            Log::warning('Failed to subscribe to Messenger webhooks', [
                'connection_id' => $connection->id,
                'page_id' => $pageId,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new Exception('Failed to subscribe to Messenger webhooks: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
        }
    }
}
