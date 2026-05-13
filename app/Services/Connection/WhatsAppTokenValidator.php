<?php

namespace App\Services\Connection;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validates WhatsApp Cloud API access tokens via Meta's debug_token endpoint
 * using an App Access Token, and reacts to revoked tokens.
 *
 * Why this exists: Embedded Signup tokens are SYSTEM_USER tokens scoped to a
 * WABA. There is no API path that maps such a token back to the Facebook user
 * ASID Meta sends in deauth/data-deletion signed_requests. Instead of trying
 * to match on user_id, we periodically (and reactively) check token validity:
 * once a user revokes the app, debug_token returns is_valid=false for the
 * token regardless of which user revoked it.
 */
class WhatsAppTokenValidator
{
    /**
     * Inspect a single access token. Returns the raw `data` payload from
     * /debug_token, or null on transport failure. Caller should treat null as
     * "unknown — do not act" (e.g. transient network error), and act only on
     * an explicit `is_valid: false`.
     */
    public function inspect(string $accessToken): ?array
    {
        $appId = config('services.facebook.app_id');
        $appSecret = config('services.facebook.app_secret');

        if (!$appId || !$appSecret) {
            Log::error('WhatsAppTokenValidator: missing facebook.app_id/app_secret config');
            return null;
        }

        // /debug_token is NOT version-namespaced — calling /v25.0/debug_token
        // returns "Unsupported request - method type: get" (GraphMethodException).
        $response = Http::get('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => "{$appId}|{$appSecret}",
        ]);

        if (!$response->successful()) {
            Log::warning('WhatsAppTokenValidator: debug_token request failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return null;
        }

        return $response->json('data');
    }

    /**
     * True only if Meta explicitly returns is_valid=false. Other states (null,
     * missing key, transport error) are treated as "still valid" so we never
     * deauthorize on a transient failure.
     */
    public function isExplicitlyInvalid(string $accessToken): bool
    {
        $data = $this->inspect($accessToken);
        if ($data === null) {
            return false;
        }
        return ($data['is_valid'] ?? true) === false;
    }

    /**
     * Walk every active WhatsApp Official connection, ask Meta whether its
     * stored token is still valid, and mark Inactive any whose token Meta
     * reports as revoked. Returns the connections that were deauthorized.
     */
    public function deauthorizeRevoked(): Collection
    {
        $connections = Connection::where('channel', Channel::WhatsappOfficial)
            ->where('status', Status::Active)
            ->whereNotNull('credentials')
            ->get();

        $deauthorized = collect();

        foreach ($connections as $connection) {
            $token = $connection->credentials['access_token'] ?? null;
            if (!$token) {
                continue;
            }

            if (!$this->isExplicitlyInvalid($token)) {
                continue;
            }

            $connection->update(['status' => Status::Inactive]);
            broadcast(new ConnectionUpdated($connection->fresh()));
            $deauthorized->push($connection);

            Log::info('WhatsApp connection deauthorized via token validation', [
                'connection_id' => $connection->id,
                'phone_number_id' => $connection->credentials['phone_number_id'] ?? null,
            ]);
        }

        return $deauthorized;
    }

    /**
     * Same as deauthorizeRevoked but also deletes conversations + messages for
     * any connection whose token Meta reports as revoked. Returns counters
     * suitable for logging in a Meta data-deletion audit row.
     *
     * @return array{connections: int, conversations: int, messages: int, connection_ids: array<int>}
     */
    public function deleteRevokedData(): array
    {
        $connections = Connection::where('channel', Channel::WhatsappOfficial)
            ->whereNotNull('credentials')
            ->get();

        $stats = [
            'connections' => 0,
            'conversations' => 0,
            'messages' => 0,
            'connection_ids' => [],
        ];

        foreach ($connections as $connection) {
            $token = $connection->credentials['access_token'] ?? null;
            if (!$token) {
                continue;
            }

            if (!$this->isExplicitlyInvalid($token)) {
                continue;
            }

            $conversations = Conversation::where('connection_id', $connection->id)->get();

            foreach ($conversations as $conversation) {
                $messageCount = Message::where('conversation_id', $conversation->id)->count();
                Message::where('conversation_id', $conversation->id)->delete();
                $stats['messages'] += $messageCount;

                $conversation->tags()->detach();
                $conversation->delete();
                $stats['conversations']++;
            }

            // Keep the connection row as a historical record but wipe credentials.
            $connection->update([
                'status' => Status::Inactive,
                'credentials' => null,
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            $stats['connections']++;
            $stats['connection_ids'][] = $connection->id;

            Log::info('WhatsApp connection data deleted via token validation', [
                'connection_id' => $connection->id,
                'conversations_deleted' => $conversations->count(),
            ]);
        }

        return $stats;
    }
}
