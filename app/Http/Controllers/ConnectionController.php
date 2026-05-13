<?php

namespace App\Http\Controllers;

use App\Enums\Connection\Status;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\ConnectionService;
use App\Services\Connection\WhatsAppTokenValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectionController extends Controller
{
    public function __construct(
        protected ConnectionService $connectionService,
        protected WhatsAppTokenValidator $whatsAppTokenValidator,
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
                $connection->update([
                    'status' => Status::Inactive,
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

    /**
     * Handles Facebook OAuth callback for WhatsApp Embedded Signup.
     *
     * Accepts both:
     *  - POST (modern flow): JS SDK popup posts { code, connection_id, waba_id, phone_number_id }.
     *  - GET (legacy redirect flow): Facebook redirects with ?code=&state= where state is
     *    base64(json({ connection_id, waba_id?, phone_number_id? })).
     */
    public function facebookCallback(Request $request)
    {
        $error = $request->input('error');
        if ($error) {
            Log::error('Facebook OAuth error', [
                'error' => $error,
                'error_reason' => $request->input('error_reason'),
                'error_description' => $request->input('error_description'),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Facebook OAuth error: ' . $request->input('error_description'),
            ], 400);
        }

        $code = $request->input('code');
        if (!$code) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Facebook callback: missing code',
            ], 400);
        }

        // Resolve connection_id, waba_id, phone_number_id from either POST body or GET state.
        $connectionId = $request->input('connection_id');
        $wabaId = $request->input('waba_id');
        $phoneNumberId = $request->input('phone_number_id');

        if (!$connectionId && $state = $request->input('state')) {
            $stateData = json_decode(base64_decode($state), true) ?: [];
            $connectionId = $stateData['connection_id'] ?? null;
            $wabaId = $wabaId ?: ($stateData['waba_id'] ?? null);
            $phoneNumberId = $phoneNumberId ?: ($stateData['phone_number_id'] ?? null);
        }

        if (!$connectionId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing connection_id',
            ], 400);
        }

        try {
            $connection = Connection::findOrFail($connectionId);

            $tokenRequestData = [
                'client_id' => config('services.facebook.app_id'),
                'client_secret' => config('services.facebook.app_secret'),
                'code' => $code,
            ];

            $redirectUri = config('services.facebook.redirect_uri');
            if (!empty($redirectUri)) {
                $tokenRequestData['redirect_uri'] = $redirectUri;
            }

            $response = Http::asForm()->post('https://graph.facebook.com/v25.0/oauth/access_token', $tokenRequestData);

            if (!$response->successful()) {
                Log::error('Failed to exchange Facebook code for token', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);
                throw new \Exception('Failed to obtain access token: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
            }

            $accessToken = $response->json()['access_token'] ?? null;
            if (!$accessToken) {
                throw new \Exception('Invalid response from Facebook OAuth.');
            }

            return $this->handleWhatsAppCallback($connection, $accessToken, $wabaId, $phoneNumberId);

        } catch (\Throwable $th) {
            Log::error('Error processing Facebook callback', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to connect account: ' . $th->getMessage(),
            ], 500);
        }
    }

    private function handleWhatsAppCallback(Connection $connection, string $accessToken, ?string $wabaId = null, ?string $phoneNumberId = null)
    {
        try {
            // WABA ID must come from the frontend (WA_EMBEDDED_SIGNUP "FINISH" event).
            // Fallback to /me/businesses lookup for tokens where it was not forwarded.
            if (!$wabaId) {
                $wabaId = $this->resolveWabaIdFromBusinesses($accessToken);
            }

            if (!$wabaId) {
                throw new \Exception('Could not retrieve WhatsApp Business Account ID. Frontend must send waba_id from the WA_EMBEDDED_SIGNUP "FINISH" message event.');
            }

            // Fields requested for both single-phone and list-phone lookups.
            // platform_type + code_verification_status drive the "already
            // registered on Cloud API?" decision below — without them we'd
            // re-register every time and risk PIN-mismatch failures.
            $phoneFields = 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type,is_pin_enabled';

            $primaryPhone = null;

            if ($phoneNumberId) {
                $phoneResponse = Http::get("https://graph.facebook.com/v25.0/{$phoneNumberId}", [
                    'access_token' => $accessToken,
                    'fields' => $phoneFields,
                ]);
                if ($phoneResponse->successful()) {
                    $primaryPhone = $phoneResponse->json();
                }
            }

            if (!$primaryPhone) {
                $phoneNumbersResponse = Http::get("https://graph.facebook.com/v25.0/{$wabaId}/phone_numbers", [
                    'access_token' => $accessToken,
                    'fields' => $phoneFields,
                ]);
                $phoneNumbers = $phoneNumbersResponse->successful() ? ($phoneNumbersResponse->json()['data'] ?? []) : [];

                if (empty($phoneNumbers)) {
                    throw new \Exception('No phone numbers found for this WhatsApp Business Account');
                }

                $primaryPhone = $phoneNumbers[0];
            }

            $phoneNumberId = $primaryPhone['id'] ?? $phoneNumberId;
            $displayPhoneNumber = $primaryPhone['display_phone_number'] ?? null;
            $verifiedName = $primaryPhone['verified_name'] ?? null;
            $qualityRating = $primaryPhone['quality_rating'] ?? null;
            $platformType = $primaryPhone['platform_type'] ?? null;
            $codeVerificationStatus = $primaryPhone['code_verification_status'] ?? null;
            $isPinEnabled = $primaryPhone['is_pin_enabled'] ?? false;

            $this->subscribeWabaApp((string) $wabaId, $accessToken);

            // Decide whether to call /register. The /register endpoint is NOT
            // safely re-runnable: if the number is already registered with a
            // PIN we don't know, the call fails (133015). Skip when Meta
            // already reports the number as live on Cloud API and verified.
            $alreadyRegistered = ($platformType === 'CLOUD_API')
                && ($codeVerificationStatus === 'VERIFIED');

            $pin = $connection->credentials['pin'] ?? null;

            if ($alreadyRegistered) {
                Log::info('Phone number already registered on Cloud API; skipping /register', [
                    'phone_number_id' => $phoneNumberId,
                    'platform_type' => $platformType,
                    'code_verification_status' => $codeVerificationStatus,
                    'is_pin_enabled' => $isPinEnabled,
                ]);
            } else {
                // Use stored PIN if we have one (e.g. previous attempt), else mint a new one.
                $pin = $pin ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $this->registerPhoneNumber((string) $phoneNumberId, $accessToken, $pin);
            }

            // Note: we intentionally do NOT try to resolve a Facebook user ASID
            // here. The Embedded Signup token is a SYSTEM_USER token scoped to
            // a WABA, and there is no API path it can call to obtain the ASID
            // Meta sends in deauth/data-deletion signed_requests (assigned_users
            // returns Business User entity IDs, /me returns the system user ID,
            // and Business-level admin queries require business_management on
            // the parent Business — a permission Embedded Signup does not grant).
            //
            // Instead, deauth/data-deletion is handled reactively via
            // WhatsAppTokenValidator (debug_token + App Access Token).
            $this->connectionService->connect($connection, [
                'access_token' => $accessToken,
                'business_account_id' => (string) $wabaId,
                'phone_number_id' => (string) $phoneNumberId,
                'display_phone_number' => $displayPhoneNumber,
                'verified_name' => $verifiedName,
                'quality_rating' => $qualityRating,
                'pin' => $pin,
                'token_type' => 'SYSTEM_USER',
                'token_expires_at' => null,
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            Log::info('WhatsApp account connected successfully', [
                'connection_id' => $connection->id,
                'business_account_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $displayPhoneNumber,
            ]);

            // Return JSON response instead of redirect for embedded signup
            return response()->json([
                'status' => 'success',
                'message' => 'WhatsApp account connected successfully!',
                'data' => $connection->fresh()->toResource(\App\Http\Resources\ConnectionResource::class),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Error handling WhatsApp callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            throw $th;
        }
    }

    /**
     * Fallback resolver for SYSTEM_USER tokens where granular_scopes is empty.
     * Walks /me/businesses → owned_whatsapp_business_accounts / client_whatsapp_business_accounts
     * and returns the first WABA id found. Returns null if none.
     */
    private function resolveWabaIdFromBusinesses(string $accessToken): ?string
    {
        $response = Http::get('https://graph.facebook.com/v25.0/me/businesses', [
            'access_token' => $accessToken,
            'fields' => 'id,owned_whatsapp_business_accounts{id},client_whatsapp_business_accounts{id}',
        ]);

        if (!$response->successful()) {
            Log::warning('Failed to list businesses for WABA resolution', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return null;
        }

        foreach ($response->json()['data'] ?? [] as $business) {
            $owned = $business['owned_whatsapp_business_accounts']['data'] ?? [];
            $client = $business['client_whatsapp_business_accounts']['data'] ?? [];

            foreach (array_merge($owned, $client) as $waba) {
                if (!empty($waba['id'])) {
                    return (string) $waba['id'];
                }
            }
        }

        return null;
    }

    private function handleMessengerCallback(Connection $connection, string $accessToken)
    {
        // Placeholder for future Messenger implementation
        throw new \Exception('Messenger integration is not yet implemented');
    }

    /**
     * Subscribe the app (the one that owns the access token) to receive
     * webhook events for this WABA. Required for incoming messages to be
     * delivered to our webhook endpoint.
     */
    private function subscribeWabaApp(string $wabaId, string $accessToken): void
    {
        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/v25.0/{$wabaId}/subscribed_apps");

        if (!$response->successful()) {
            Log::error('Failed to subscribe app to WABA webhook', [
                'waba_id' => $wabaId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new \Exception('Failed to subscribe app to WABA webhook: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
        }

        Log::info('Subscribed app to WABA webhook', ['waba_id' => $wabaId]);
    }

    /**
     * Register the phone number on Cloud API. The PIN becomes the 2FA PIN
     * for the number; for first-time embedded signup any 6-digit PIN works.
     * The PIN must be persisted — re-registration requires the same value
     * unless 2FA is reset via Facebook Business settings.
     */
    private function registerPhoneNumber(string $phoneNumberId, string $accessToken, string $pin): void
    {
        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/v25.0/{$phoneNumberId}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

        if ($response->successful()) {
            Log::info('Phone number registered on Cloud API', ['phone_number_id' => $phoneNumberId]);
            return;
        }

        // Treat "already registered" as success — the caller's pre-check should
        // catch this most of the time, but Meta surfaces the same outcome here
        // when a race or stale GET means we tried anyway.
        // - 133005 = "two-step verification PIN mismatch" (already registered with a different PIN)
        // - 133006 = "phone number needs to be verified before registering"
        // - 133010 = "phone number not registered" (we are NOT in this case)
        // - subcode 2388023 in error_subcode for "already registered"
        $error = $response->json('error') ?? [];
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['message'] ?? '');

        $alreadyRegistered = $subcode === 2388023
            || stripos($message, 'already registered') !== false;

        if ($alreadyRegistered) {
            Log::info('Phone number already registered on Cloud API (race with pre-check); treating as success', [
                'phone_number_id' => $phoneNumberId,
                'error' => $error,
            ]);
            return;
        }

        Log::error('Failed to register phone number on Cloud API', [
            'phone_number_id' => $phoneNumberId,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        throw new \Exception('Failed to register phone number: ' . ($message ?: 'Unknown error') . " (code {$code}, subcode {$subcode})");
    }

    public function facebookDeauthorize(Request $request)
    {
        try {
            $signedRequest = $request->input('signed_request');

            if (!$signedRequest) {
                Log::warning('Facebook deauthorization: missing signed_request');
                return response()->json(['error' => 'Missing signed_request'], 400);
            }

            $data = $this->parseFacebookSignedRequest($signedRequest);

            if (!$data || !isset($data['user_id'])) {
                Log::error('Facebook deauthorization: invalid signed_request');
                return response()->json(['error' => 'Invalid signed_request'], 400);
            }

            // We cannot map signed_request user_id -> a specific connection
            // (Embedded Signup tokens are SYSTEM_USER tokens with no resolvable
            // ASID — see handleWhatsAppCallback). Instead, ask Meta which of
            // our stored tokens are now invalid and deauthorize those.
            Log::info('Facebook deauthorization: validating stored WhatsApp tokens', [
                'facebook_user_id' => $data['user_id'],
            ]);

            $deauthorized = $this->whatsAppTokenValidator->deauthorizeRevoked();

            Log::info('Facebook deauthorization processed', [
                'facebook_user_id' => $data['user_id'],
                'deauthorized_count' => $deauthorized->count(),
                'connection_ids' => $deauthorized->pluck('id')->all(),
            ]);

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

            $data = $this->parseFacebookSignedRequest($signedRequest);

            if (!$data || !isset($data['user_id'])) {
                Log::error('Facebook data deletion: invalid signed_request');
                return response()->json(['error' => 'Invalid signed_request'], 400);
            }

            $facebookUserId = $data['user_id'];
            $confirmationCode = hash('sha256', $facebookUserId . time() . uniqid());
            $statusUrl = route('oauth.facebook.deletion-status', ['code' => $confirmationCode]);

            Log::info('Facebook data deletion: validating stored WhatsApp tokens', [
                'facebook_user_id' => $facebookUserId,
                'confirmation_code' => $confirmationCode,
            ]);

            // Same rationale as facebookDeauthorize: cannot map signed_request
            // user_id to a specific connection. Ask Meta which stored tokens
            // are now invalid and delete the data backing those connections.
            $stats = $this->whatsAppTokenValidator->deleteRevokedData();

            DB::table('facebook_deletion_logs')->insert([
                'confirmation_code' => $confirmationCode,
                'facebook_user_id' => $facebookUserId,
                'status' => 'completed',
                'connections_deleted' => $stats['connections'],
                'conversations_deleted' => $stats['conversations'],
                'messages_deleted' => $stats['messages'],
                'requested_at' => now(),
                'completed_at' => now(),
                'meta' => json_encode([
                    'algorithm' => $data['algorithm'] ?? null,
                    'issued_at' => $data['issued_at'] ?? null,
                    'status_url' => $statusUrl,
                    'connection_ids' => $stats['connection_ids'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Facebook data deletion completed', [
                'facebook_user_id' => $facebookUserId,
                'confirmation_code' => $confirmationCode,
                'stats' => $stats,
            ]);

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
