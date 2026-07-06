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
     * Probe the token with an actual API call to /me. Returns true if Meta
     * explicitly rejects the token as revoked/expired (OAuthException code 190),
     * false otherwise. Transport errors and unrelated HTTP failures are treated
     * as "still valid" so we never deauthorize on a transient blip.
     *
     * We use /me instead of /debug_token because:
     *   - /debug_token returns GraphMethodException for WhatsApp Business app
     *     tokens in this environment.
     *   - /me deterministically returns code 190 once the user revokes the app,
     *     and that's the only signal we actually need.
     */
    public function isExplicitlyInvalid(string $accessToken): bool
    {
        $response = Http::get('https://graph.facebook.com/v25.0/me', [
            'access_token' => $accessToken,
            'fields' => 'id',
        ]);

        if ($response->successful()) {
            return false;
        }

        $error = $response->json('error') ?? [];
        $type = $error['type'] ?? null;
        $code = $error['code'] ?? null;

        // Meta returns OAuthException code 190 for any auth-side rejection:
        // revoked, expired, password-change invalidation, etc.
        if ($type === 'OAuthException' && (int) $code === 190) {
            return true;
        }

        Log::warning('WhatsAppTokenValidator: /me probe returned a non-auth error; treating token as still valid', [
            'status' => $response->status(),
            'error' => $error,
        ]);
        return false;
    }

    /**
     * Number of connections the revoked-token scan would probe (one Graph API
     * call each). Callers use this to decide whether to run the scan inline or
     * push it to a queue to avoid timing out a synchronous webhook request.
     */
    public function revocationScanCandidateCount(): int
    {
        return Connection::where('channel', Channel::WhatsappOfficial)
            ->where('status', Status::Active)
            ->whereNotNull('credentials')
            ->count();
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
     * Mark Inactive every WhatsApp Official connection whose stored fb_user_id
     * matches the app-scoped user id from a Meta deauth signed_request. This is
     * the reliable, exact mapping (the ASID stored at connect == the ASID Meta
     * sends here). Returns the connections that were deauthorized.
     */
    public function deauthorizeByFacebookUserId(string $facebookUserId): Collection
    {
        $connections = Connection::where('channel', Channel::WhatsappOfficial)
            ->where('credentials->fb_user_id', $facebookUserId)
            ->get();

        $deauthorized = collect();

        foreach ($connections as $connection) {
            $connection->update(['status' => Status::Inactive]);
            broadcast(new ConnectionUpdated($connection->fresh()));
            $deauthorized->push($connection);

            Log::info('WhatsApp connection deauthorized via fb_user_id match', [
                'connection_id' => $connection->id,
                'fb_user_id' => $facebookUserId,
            ]);
        }

        return $deauthorized;
    }

    /**
     * Delete conversations + messages and wipe credentials for every WhatsApp
     * Official connection whose stored fb_user_id matches the app-scoped user id
     * from a Meta data-deletion signed_request. This is the exact mapping that
     * makes data deletion actually delete (vs. the token-revocation fallback,
     * which never fires for SYSTEM_USER tokens that stay valid after removal).
     *
     * @return array{connections: int, conversations: int, messages: int, connection_ids: array<int>}
     */
    public function deleteDataByFacebookUserId(string $facebookUserId): array
    {
        $connections = Connection::where('channel', Channel::WhatsappOfficial)
            ->where('credentials->fb_user_id', $facebookUserId)
            ->get();

        return $this->deleteConnectionsData($connections);
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
            ->get()
            ->filter(function (Connection $connection) {
                $token = $connection->credentials['access_token'] ?? null;
                return $token && $this->isExplicitlyInvalid($token);
            });

        return $this->deleteConnectionsData($connections);
    }

    /**
     * Delete conversations + messages and wipe credentials for the given
     * connections. Keeps the connection rows as historical records. Returns
     * counters suitable for a Meta data-deletion audit row.
     *
     * @param  \Illuminate\Support\Collection<int, Connection>  $connections
     * @return array{connections: int, conversations: int, messages: int, connection_ids: array<int>}
     */
    private function deleteConnectionsData(Collection $connections): array
    {
        $stats = [
            'connections' => 0,
            'conversations' => 0,
            'messages' => 0,
            'connection_ids' => [],
        ];

        foreach ($connections as $connection) {
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

            Log::info('WhatsApp connection data deleted', [
                'connection_id' => $connection->id,
                'conversations_deleted' => $conversations->count(),
            ]);
        }

        return $stats;
    }
}
