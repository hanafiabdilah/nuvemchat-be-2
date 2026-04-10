<?php

namespace App\Http\Controllers;

use App\Enums\Connection\Status;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\ConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectionController extends Controller
{
    public function __construct(
        protected ConnectionService $connectionService,
    ){
        //
    }

    public function instagramCallback(Request $request)
    {
        Log::info('Instagram OAuth callback received', [
            'query' => $request->query(),
        ]);

        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorReason = $request->query('error_reason');
        $errorDescription = $request->query('error_description');

        // Handle error from Instagram
        if ($error) {
            Log::error('Instagram OAuth error', [
                'error' => $error,
                'error_reason' => $errorReason,
                'error_description' => $errorDescription,
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Instagram OAuth error: ' . $errorDescription));
        }

        // Validate required parameters
        if (!$code || !$state) {
            Log::error('Missing code or state parameter in Instagram callback');
            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Invalid Instagram callback: missing code or state parameter'));
        }

        // Decode state to get connection_id
        try {
            $stateData = json_decode(base64_decode($state), true);
            $connectionId = $stateData['connection_id'] ?? null;

            if (!$connectionId) {
                throw new \Exception('Invalid state parameter');
            }

            // Find the connection
            $connection = Connection::findOrFail($connectionId);

            // Exchange code for access token (Instagram Business API)
            $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => config('services.instagram.client_id'),
                'client_secret' => config('services.instagram.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.instagram.redirect_uri'),
                'code' => $code,
            ]);

            if (!$response->successful()) {
                Log::error('Failed to exchange Instagram code for token', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);
                throw new \Exception('Failed to obtain access token from Instagram: ' . ($response->json()['error_message'] ?? 'Unknown error'));
            }

            $data = $response->json();
            $shortLivedToken = $data['access_token'] ?? null;
            $userId = $data['user_id'] ?? null;

            if (!$shortLivedToken || !$userId) {
                throw new \Exception('Invalid response from Instagram OAuth.');
            }

            // Exchange short-lived token for long-lived token (60 days)
            $longLivedTokenResponse = Http::get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => config('services.instagram.client_secret'),
                'access_token' => $shortLivedToken,
            ]);

            if ($longLivedTokenResponse->successful()) {
                $tokenData = $longLivedTokenResponse->json();
                $accessToken = $tokenData['access_token'] ?? $shortLivedToken;
                $expiresIn = $tokenData['expires_in'] ?? 5184000; // Default 60 days in seconds
                $tokenExpiresAt = now()->addSeconds($expiresIn)->toDateTimeString();
                Log::info('Successfully exchanged for long-lived token', [
                    'expires_in' => $expiresIn,
                    'expires_at' => $tokenExpiresAt,
                ]);
            } else {
                Log::warning('Failed to get long-lived token, using short-lived token', [
                    'response' => $longLivedTokenResponse->json(),
                ]);
                $accessToken = $shortLivedToken;
                $tokenExpiresAt = null;
            }

            // Get Instagram Business Account info
            $accountResponse = Http::get("https://graph.instagram.com/v25.0/me", [
                'fields' => 'id,username,user_id,name,profile_picture_url',
                'access_token' => $accessToken,
            ]);

            $accountInfo = $accountResponse->successful() ? $accountResponse->json() : [];

            Log::info('Instagram account info retrieved', [
                'account_info' => $accountInfo,
                'user_id' => $accountInfo['user_id'] ?? null,
            ]);

            // Connect the Instagram account using ConnectionService
            $this->connectionService->connect($connection, [
                'access_token' => $accessToken,
                'page_id' => (string) $userId,
                'instagram_account_id' => $accountInfo['id'] ?? $userId,
                'user_id' => $accountInfo['user_id'] ?? null,
                'username' => $accountInfo['username'] ?? null,
                'token_expires_at' => $tokenExpiresAt,
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            Log::info('Instagram account connected successfully', [
                'connection_id' => $connectionId,
                'instagram_account_id' => $accountInfo['id'] ?? $userId,
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=success&message=' . urlencode('Instagram account connected successfully!'));

        } catch (\Throwable $th) {
            Log::error('Error processing Instagram callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Failed to connect Instagram account: ' . $th->getMessage()));
        }
    }

    public function instagramDeauthorize(Request $request)
    {
        try {
            $signedRequest = $request->input('signed_request');

            if (!$signedRequest) {
                Log::warning('Instagram deauthorization: missing signed_request');
                return response()->json(['error' => 'Missing signed_request'], 400);
            }

            // Parse signed request
            $data = $this->parseSignedRequest($signedRequest);

            if (!$data || !isset($data['user_id'])) {
                Log::error('Instagram deauthorization: invalid signed_request');
                return response()->json(['error' => 'Invalid signed_request'], 400);
            }

            $instagramUserId = $data['user_id'];

            Log::info('Instagram deauthorization processing', [
                'instagram_user_id' => $instagramUserId,
            ]);

            // Find all connections with this Instagram user_id
            $connections = Connection::where('channel', 'instagram')
                ->where(function ($query) use ($instagramUserId) {
                    $query->whereJsonContains('credentials->user_id', $instagramUserId)
                          ->orWhereJsonContains('credentials->instagram_account_id', $instagramUserId)
                          ->orWhereJsonContains('credentials->page_id', $instagramUserId);
                })
                ->get();

            foreach ($connections as $connection) {
                // Disconnect by removing credentials
                $connection->update([
                    'status' => Status::Inactive,
                    'credentials' => null,
                ]);

                broadcast(new ConnectionUpdated($connection->fresh()));

                Log::info('Instagram connection deauthorized', [
                    'connection_id' => $connection->id,
                    'instagram_user_id' => $instagramUserId,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Deauthorization processed successfully',
            ]);

        } catch (\Throwable $th) {
            Log::error('Error processing Instagram deauthorization', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process deauthorization',
            ], 500);
        }
    }

    public function instagramDataDeletion(Request $request)
    {
        try {
            $signedRequest = $request->input('signed_request');

            if (!$signedRequest) {
                Log::warning('Instagram data deletion: missing signed_request');
                return response()->json(['error' => 'Missing signed_request'], 400);
            }

            // Parse signed request
            $data = $this->parseSignedRequest($signedRequest);

            if (!$data || !isset($data['user_id'])) {
                Log::error('Instagram data deletion: invalid signed_request');
                return response()->json(['error' => 'Invalid signed_request'], 400);
            }

            $instagramUserId = $data['user_id'];

            // Generate confirmation code
            $confirmationCode = hash('sha256', $instagramUserId . time() . uniqid());
            $statusUrl = route('instagram.deletion-status', ['code' => $confirmationCode]);

            Log::info('Instagram data deletion processing', [
                'instagram_user_id' => $instagramUserId,
                'confirmation_code' => $confirmationCode,
                'status_url' => $statusUrl,
            ]);

            // Initialize counters
            $connectionsAffected = 0;
            $conversationsDeleted = 0;
            $messagesDeleted = 0;

            // Find all connections with this Instagram user_id
            $connections = Connection::where('channel', 'instagram')
                ->where(function ($query) use ($instagramUserId) {
                    $query->whereJsonContains('credentials->user_id', $instagramUserId)
                          ->orWhereJsonContains('credentials->instagram_account_id', $instagramUserId)
                          ->orWhereJsonContains('credentials->page_id', $instagramUserId);
                })
                ->get();

            foreach ($connections as $connection) {
                // Delete all conversations and messages related to this connection
                $conversations = $connection->conversations ?? Conversation::where('connection_id', $connection->id)->get();

                foreach ($conversations as $conversation) {
                    // Count and delete all messages in this conversation
                    $messageCount = Message::where('conversation_id', $conversation->id)->count();
                    Message::where('conversation_id', $conversation->id)->delete();
                    $messagesDeleted += $messageCount;

                    // Delete conversation tags
                    $conversation->tags()->detach();

                    // Delete the conversation
                    $conversation->delete();
                    $conversationsDeleted++;
                }

                // Disconnect by removing credentials and setting status
                // DO NOT delete the connection - keep it as historical record
                $connection->update([
                    'status' => Status::Inactive,
                    'credentials' => null,
                ]);

                $connectionsAffected++;

                broadcast(new ConnectionUpdated($connection->fresh()));

                Log::info('Instagram connection data deleted', [
                    'connection_id' => $connection->id,
                    'instagram_user_id' => $instagramUserId,
                    'conversations_deleted' => count($conversations),
                ]);
            }

            // Save deletion log to database for audit
            DB::table('instagram_deletion_logs')->insert([
                'confirmation_code' => $confirmationCode,
                'instagram_user_id' => $instagramUserId,
                'status' => 'completed',
                'connections_deleted' => $connectionsAffected,
                'conversations_deleted' => $conversationsDeleted,
                'messages_deleted' => $messagesDeleted,
                'requested_at' => now(),
                'completed_at' => now(),
                'meta' => json_encode([
                    'algorithm' => $data['algorithm'] ?? null,
                    'issued_at' => $data['issued_at'] ?? null,
                    'status_url' => $statusUrl,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Instagram data deletion completed', [
                'instagram_user_id' => $instagramUserId,
                'confirmation_code' => $confirmationCode,
                'status_url' => $statusUrl,
                'stats' => [
                    'connections_affected' => $connectionsAffected,
                    'conversations_deleted' => $conversationsDeleted,
                    'messages_deleted' => $messagesDeleted,
                ],
            ]);

            // Meta expects this specific response format
            return response()->json([
                'url' => $statusUrl,
                'confirmation_code' => $confirmationCode,
            ]);

        } catch (\Throwable $th) {
            Log::error('Error processing Instagram data deletion', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process data deletion',
            ], 500);
        }
    }

    public function instagramDeletionStatus(Request $request)
    {
        $code = $request->query('code');

        if (!$code) {
            return response()->view('instagram.deletion-status-error', [
                'error' => 'Missing confirmation code',
            ], 400);
        }

        try {
            // Find deletion log by confirmation code
            $log = DB::table('instagram_deletion_logs')
                ->where('confirmation_code', $code)
                ->first();

            if (!$log) {
                return response()->view('instagram.deletion-status-error', [
                    'error' => 'Invalid confirmation code',
                ], 404);
            }

            // Return status page
            return view('instagram.deletion-status', [
                'log' => $log,
            ]);

        } catch (\Throwable $th) {
            Log::error('Error retrieving deletion status', [
                'error' => $th->getMessage(),
                'code' => $code,
            ]);

            return response()->view('instagram.deletion-status-error', [
                'error' => 'Failed to retrieve deletion status',
            ], 500);
        }
    }

    public function facebookCallback(Request $request)
    {
        Log::info('Facebook OAuth callback received', [
            'query' => $request->query(),
        ]);

        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $errorReason = $request->query('error_reason');
        $errorDescription = $request->query('error_description');

        // Handle error from Facebook
        if ($error) {
            Log::error('Facebook OAuth error', [
                'error' => $error,
                'error_reason' => $errorReason,
                'error_description' => $errorDescription,
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Facebook OAuth error: ' . $errorDescription));
        }

        // Validate required parameters
        if (!$code || !$state) {
            Log::error('Missing code or state parameter in Facebook callback');
            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Invalid Facebook callback: missing code or state parameter'));
        }

        // Decode state to get connection_id and platform
        try {
            $stateData = json_decode(base64_decode($state), true);
            $connectionId = $stateData['connection_id'] ?? null;
            $platform = $stateData['platform'] ?? 'whatsapp'; // default to whatsapp

            if (!$connectionId) {
                throw new \Exception('Invalid state parameter');
            }

            // Find the connection
            $connection = Connection::findOrFail($connectionId);

            // Exchange code for access token
            $response = Http::asForm()->post('https://graph.facebook.com/v21.0/oauth/access_token', [
                'client_id' => config('services.facebook.app_id'),
                'client_secret' => config('services.facebook.app_secret'),
                'redirect_uri' => config('services.facebook.redirect_uri'),
                'code' => $code,
            ]);

            if (!$response->successful()) {
                Log::error('Failed to exchange Facebook code for token', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);
                throw new \Exception('Failed to obtain access token from Facebook: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
            }

            $data = $response->json();
            $accessToken = $data['access_token'] ?? null;

            if (!$accessToken) {
                throw new \Exception('Invalid response from Facebook OAuth.');
            }

            // Route to appropriate handler based on platform
            if ($platform === 'whatsapp') {
                return $this->handleWhatsAppCallback($connection, $accessToken);
            } elseif ($platform === 'messenger') {
                return $this->handleMessengerCallback($connection, $accessToken);
            } else {
                throw new \Exception('Unknown platform: ' . $platform);
            }

        } catch (\Throwable $th) {
            Log::error('Error processing Facebook callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Failed to connect account: ' . $th->getMessage()));
        }
    }

    private function handleWhatsAppCallback(Connection $connection, string $accessToken)
    {
        try {
            // Get WhatsApp Business Account info
            $response = Http::get('https://graph.facebook.com/v21.0/debug_token', [
                'input_token' => $accessToken,
                'access_token' => config('services.facebook.app_id') . '|' . config('services.facebook.app_secret'),
            ]);

            $debugInfo = $response->successful() ? $response->json()['data'] : [];

            // Get WABA ID from the access token scopes or make additional API call
            // For embedded signup, WABA info is typically returned in the signup flow
            $wabaId = $debugInfo['granular_scopes'][0]['target_ids'][0] ?? null;

            if (!$wabaId) {
                // Alternative: Get from me endpoint with whatsapp_business_management permission
                $meResponse = Http::get('https://graph.facebook.com/v21.0/me', [
                    'fields' => 'id,name',
                    'access_token' => $accessToken,
                ]);

                $meData = $meResponse->successful() ? $meResponse->json() : [];
                Log::info('WhatsApp me data', $meData);
            }

            Log::info('WhatsApp account info retrieved', [
                'debug_info' => $debugInfo,
                'waba_id' => $wabaId,
            ]);

            // Connect the WhatsApp account using ConnectionService
            $this->connectionService->connect($connection, [
                'access_token' => $accessToken,
                'business_account_id' => (string) $wabaId,
                'phone_number_id' => null, // Will be updated when we get phone number
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            Log::info('WhatsApp account connected successfully', [
                'connection_id' => $connection->id,
                'business_account_id' => $wabaId,
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=success&message=' . urlencode('WhatsApp account connected successfully!'));

        } catch (\Throwable $th) {
            Log::error('Error handling WhatsApp callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            throw $th;
        }
    }

    private function handleMessengerCallback(Connection $connection, string $accessToken)
    {
        // Placeholder for future Messenger implementation
        throw new \Exception('Messenger integration is not yet implemented');
    }

    public function facebookDeauthorize(Request $request)
    {
        try {
            $signedRequest = $request->input('signed_request');

            if (!$signedRequest) {
                Log::warning('Facebook deauthorization: missing signed_request');
                return response()->json(['error' => 'Missing signed_request'], 400);
            }

            // Parse signed request
            $data = $this->parseFacebookSignedRequest($signedRequest);

            if (!$data || !isset($data['user_id'])) {
                Log::error('Facebook deauthorization: invalid signed_request');
                return response()->json(['error' => 'Invalid signed_request'], 400);
            }

            $facebookUserId = $data['user_id'];

            Log::info('Facebook deauthorization processing', [
                'facebook_user_id' => $facebookUserId,
            ]);

            // Find all connections with this Facebook user_id (WhatsApp & Messenger)
            $connections = Connection::whereIn('channel', ['whatsapp_official', 'messenger'])
                ->where(function ($query) use ($facebookUserId) {
                    $query->whereJsonContains('credentials->user_id', $facebookUserId)
                          ->orWhereJsonContains('credentials->business_account_id', $facebookUserId);
                })
                ->get();

            foreach ($connections as $connection) {
                // Disconnect by removing credentials
                $connection->update([
                    'status' => Status::Inactive,
                    'credentials' => null,
                ]);

                broadcast(new ConnectionUpdated($connection->fresh()));

                Log::info('Facebook connection deauthorized', [
                    'connection_id' => $connection->id,
                    'facebook_user_id' => $facebookUserId,
                    'channel' => $connection->channel,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Deauthorization processed successfully',
            ]);

        } catch (\Throwable $th) {
            Log::error('Error processing Facebook deauthorization', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process deauthorization',
            ], 500);
        }
    }

    public function facebookDataDeletion(Request $request)
    {
        try {
            $signedRequest = $request->input('signed_request');

            if (!$signedRequest) {
                Log::warning('Facebook data deletion: missing signed_request');
                return response()->json(['error' => 'Missing signed_request'], 400);
            }

            // Parse signed request
            $data = $this->parseFacebookSignedRequest($signedRequest);

            if (!$data || !isset($data['user_id'])) {
                Log::error('Facebook data deletion: invalid signed_request');
                return response()->json(['error' => 'Invalid signed_request'], 400);
            }

            $facebookUserId = $data['user_id'];

            // Generate confirmation code
            $confirmationCode = hash('sha256', $facebookUserId . time() . uniqid());
            $statusUrl = route('oauth.facebook.deletion-status', ['code' => $confirmationCode]);

            Log::info('Facebook data deletion processing', [
                'facebook_user_id' => $facebookUserId,
                'confirmation_code' => $confirmationCode,
                'status_url' => $statusUrl,
            ]);

            // Initialize counters
            $connectionsAffected = 0;
            $conversationsDeleted = 0;
            $messagesDeleted = 0;

            // Find all connections with this Facebook user_id (WhatsApp & Messenger)
            $connections = Connection::whereIn('channel', ['whatsapp_official', 'messenger'])
                ->where(function ($query) use ($facebookUserId) {
                    $query->whereJsonContains('credentials->user_id', $facebookUserId)
                          ->orWhereJsonContains('credentials->business_account_id', $facebookUserId);
                })
                ->get();

            foreach ($connections as $connection) {
                // Delete all conversations and messages related to this connection
                $conversations = $connection->conversations ?? Conversation::where('connection_id', $connection->id)->get();

                foreach ($conversations as $conversation) {
                    // Count and delete all messages in this conversation
                    $messageCount = Message::where('conversation_id', $conversation->id)->count();
                    Message::where('conversation_id', $conversation->id)->delete();
                    $messagesDeleted += $messageCount;

                    // Delete conversation tags
                    $conversation->tags()->detach();

                    // Delete the conversation
                    $conversation->delete();
                    $conversationsDeleted++;
                }

                // Disconnect by removing credentials and setting status
                // DO NOT delete the connection - keep it as historical record
                $connection->update([
                    'status' => Status::Inactive,
                    'credentials' => null,
                ]);

                $connectionsAffected++;

                broadcast(new ConnectionUpdated($connection->fresh()));

                Log::info('Facebook connection data deleted', [
                    'connection_id' => $connection->id,
                    'facebook_user_id' => $facebookUserId,
                    'channel' => $connection->channel,
                    'conversations_deleted' => count($conversations),
                ]);
            }

            // Save deletion log to database for audit
            DB::table('facebook_deletion_logs')->insert([
                'confirmation_code' => $confirmationCode,
                'facebook_user_id' => $facebookUserId,
                'status' => 'completed',
                'connections_deleted' => $connectionsAffected,
                'conversations_deleted' => $conversationsDeleted,
                'messages_deleted' => $messagesDeleted,
                'requested_at' => now(),
                'completed_at' => now(),
                'meta' => json_encode([
                    'algorithm' => $data['algorithm'] ?? null,
                    'issued_at' => $data['issued_at'] ?? null,
                    'status_url' => $statusUrl,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Facebook data deletion completed', [
                'facebook_user_id' => $facebookUserId,
                'confirmation_code' => $confirmationCode,
                'status_url' => $statusUrl,
                'stats' => [
                    'connections_affected' => $connectionsAffected,
                    'conversations_deleted' => $conversationsDeleted,
                    'messages_deleted' => $messagesDeleted,
                ],
            ]);

            // Meta expects this specific response format
            return response()->json([
                'url' => $statusUrl,
                'confirmation_code' => $confirmationCode,
            ]);

        } catch (\Throwable $th) {
            Log::error('Error processing Facebook data deletion', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process data deletion',
            ], 500);
        }
    }

    public function facebookDeletionStatus(Request $request)
    {
        $code = $request->query('code');

        if (!$code) {
            return response()->view('facebook.deletion-status-error', [
                'error' => 'Missing confirmation code',
            ], 400);
        }

        try {
            // Find deletion log by confirmation code
            $log = DB::table('facebook_deletion_logs')
                ->where('confirmation_code', $code)
                ->first();

            if (!$log) {
                return response()->view('facebook.deletion-status-error', [
                    'error' => 'Invalid confirmation code',
                ], 404);
            }

            // Return status page
            return view('facebook.deletion-status', [
                'log' => $log,
            ]);

        } catch (\Throwable $th) {
            Log::error('Error retrieving Facebook deletion status', [
                'error' => $th->getMessage(),
                'code' => $code,
            ]);

            return response()->view('facebook.deletion-status-error', [
                'error' => 'Failed to retrieve deletion status',
            ], 500);
        }
    }

    /**
     * Parse Instagram signed request
     *
     * @param string $signedRequest
     * @return array|null
     */
    private function parseSignedRequest(string $signedRequest): ?array
    {
        try {
            list($encodedSig, $payload) = explode('.', $signedRequest, 2);

            // Decode signature
            $sig = base64_decode(strtr($encodedSig, '-_', '+/'));

            // Decode payload
            $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

            if (!$data) {
                return null;
            }

            // Verify signature
            $expectedSig = hash_hmac('sha256', $payload, config('services.instagram.client_secret'), true);

            if ($sig !== $expectedSig) {
                Log::warning('Instagram signed request: signature mismatch');
                // Still return data for logging/debugging, but you might want to reject in production
            }

            return $data;
        } catch (\Throwable $th) {
            Log::error('Error parsing signed request', [
                'error' => $th->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse Facebook signed request (for WhatsApp & Messenger)
     *
     * @param string $signedRequest
     * @return array|null
     */
    private function parseFacebookSignedRequest(string $signedRequest): ?array
    {
        try {
            list($encodedSig, $payload) = explode('.', $signedRequest, 2);

            // Decode signature
            $sig = base64_decode(strtr($encodedSig, '-_', '+/'));

            // Decode payload
            $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

            if (!$data) {
                return null;
            }

            // Verify signature
            $expectedSig = hash_hmac('sha256', $payload, config('services.facebook.app_secret'), true);

            if ($sig !== $expectedSig) {
                Log::warning('Facebook signed request: signature mismatch');
                // Still return data for logging/debugging, but you might want to reject in production
            }

            return $data;
        } catch (\Throwable $th) {
            Log::error('Error parsing Facebook signed request', [
                'error' => $th->getMessage(),
            ]);
            return null;
        }
    }
}
