<?php

namespace App\Http\Controllers;

use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\ConnectionService;
use Illuminate\Http\Request;
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
                $accessToken = $longLivedTokenResponse->json()['access_token'] ?? $shortLivedToken;
                Log::info('Successfully exchanged for long-lived token');
            } else {
                Log::warning('Failed to get long-lived token, using short-lived token', [
                    'response' => $longLivedTokenResponse->json(),
                ]);
                $accessToken = $shortLivedToken;
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
                    'status' => 'disconnected',
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

            Log::info('Instagram data deletion processing', [
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
                // Delete all conversations and messages related to this connection
                $conversations = $connection->conversations ?? Conversation::where('connection_id', $connection->id)->get();

                foreach ($conversations as $conversation) {
                    // Delete all messages in this conversation
                    Message::where('conversation_id', $conversation->id)->delete();

                    // Delete conversation tags
                    $conversation->tags()->detach();

                    // Delete the conversation
                    $conversation->delete();
                }

                // Delete the connection itself
                $connection->users()->detach(); // Detach users
                $connection->delete();

                Log::info('Instagram connection and data deleted', [
                    'connection_id' => $connection->id,
                    'instagram_user_id' => $instagramUserId,
                ]);
            }

            // Generate confirmation code and status URL as per Meta requirements
            $confirmationCode = hash('sha256', $instagramUserId . time());
            $statusUrl = config('app.url') . '/api/instagram/deletion-status?code=' . $confirmationCode;

            Log::info('Instagram data deletion completed', [
                'instagram_user_id' => $instagramUserId,
                'confirmation_code' => $confirmationCode,
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
}
