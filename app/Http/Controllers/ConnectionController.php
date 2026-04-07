<?php

namespace App\Http\Controllers;

use App\Models\Connection;
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

            return redirect('/dashboard')->with('error', 'Instagram authorization failed: ' . ($errorDescription ?? $error));
        }

        // Validate required parameters
        if (!$code || !$state) {
            Log::error('Missing code or state parameter in Instagram callback');
            return redirect('/dashboard')->with('error', 'Invalid authorization callback from Instagram.');
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
            $redirectUri = config('services.instagram.redirect_uri');

            Log::info('Attempting to exchange Instagram code for token', [
                'redirect_uri_used' => $redirectUri,
                'code_length' => strlen($code),
            ]);

            $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => config('services.instagram.client_id'),
                'client_secret' => config('services.instagram.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

            if (!$response->successful()) {
                Log::error('Failed to exchange Instagram code for token', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                    'redirect_uri_sent' => $redirectUri,
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
            $accountResponse = Http::get("https://graph.instagram.com/v21.0/me", [
                'fields' => 'id,username,name,profile_picture_url',
                'access_token' => $accessToken,
            ]);

            $accountInfo = $accountResponse->successful() ? $accountResponse->json() : [];

            Log::info('Instagram account info retrieved', [
                'account_info' => $accountInfo,
            ]);

            // Connect the Instagram account using ConnectionService
            $this->connectionService->connect($connection, [
                'access_token' => $accessToken,
                'page_id' => $userId,
                'instagram_account_id' => $accountInfo['id'] ?? $userId,
            ]);

            Log::info('Instagram account connected successfully', [
                'connection_id' => $connectionId,
                'instagram_account_id' => $accountInfo['id'] ?? $userId,
            ]);

            return redirect('/dashboard')->with('success', 'Instagram account connected successfully!');

        } catch (\Throwable $th) {
            Log::error('Error processing Instagram callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return redirect('/dashboard')->with('error', 'Failed to connect Instagram account: ' . $th->getMessage());
        }
    }

    public function instagramDeauthorize(Request $request)
    {
        Log::info('Instagram deauthorization received', [
            'query' => $request->query(),
        ]);

        return response()->json([
            'message' => 'Instagram deauthorization received',
        ]);
    }

    public function instagramDataDeletion(Request $request)
    {
        Log::info('Instagram data deletion request received', [
            'query' => $request->query(),
        ]);

        return response()->json([
            'message' => 'Instagram data deletion request received',
        ]);
    }
}
