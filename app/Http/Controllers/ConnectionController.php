<?php

namespace App\Http\Controllers;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Events\ConnectionUpdated;
use App\Jobs\DeauthorizeRevokedWhatsAppConnections;
use App\Jobs\SyncCoexistenceSmbData;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\ConnectionService;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Connection\Meta\InstagramConfig;
use App\Services\Connection\TikTok\TikTokAuthClient;
use App\Services\Connection\WhatsAppTokenValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectionController extends Controller
{
    /**
     * Above this many connections, the revoked-token fallback scan is pushed to
     * a queue instead of running inline, so the Meta deauth webhook returns fast
     * (each connection costs one Graph API round-trip).
     */
    private const DEAUTH_SYNC_LIMIT = 100;

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
                'client_id' => InstagramConfig::clientId(),
                'client_secret' => InstagramConfig::clientSecret(),
                'grant_type' => 'authorization_code',
                'redirect_uri' => InstagramConfig::redirectUri(),
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
                'client_secret' => InstagramConfig::clientSecret(),
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

    public function tiktokCallback(Request $request)
    {
        Log::info('TikTok OAuth callback received', [
            'query' => $request->query(),
        ]);

        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        // Handle error from TikTok (user denied or cancelled the authorization)
        if ($error) {
            Log::error('TikTok OAuth error', [
                'error' => $error,
                'error_description' => $request->query('error_description'),
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('TikTok OAuth error: ' . ($request->query('error_description') ?: $error)));
        }

        if (!$code || !$state) {
            Log::error('Missing code or state parameter in TikTok callback');
            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Invalid TikTok callback: missing code or state parameter'));
        }

        try {
            $stateData = json_decode(base64_decode($state), true);
            $connectionId = $stateData['connection_id'] ?? null;

            if (!$connectionId) {
                throw new \Exception('Invalid state parameter');
            }

            $connection = Connection::findOrFail($connectionId);

            $tokens = TikTokAuthClient::exchangeCode($code);

            // Reject partial consent up-front: a missing message.* scope would
            // otherwise only surface later as failing sends or silent webhooks.
            $granted = array_filter(explode(',', (string) ($tokens['scope'] ?? '')));
            $missing = array_diff(TikTokAuthClient::REQUIRED_SCOPES, $granted);
            if (!empty($missing)) {
                throw new \Exception('User did not grant all required TikTok permissions: ' . implode(', ', $missing));
            }

            $this->connectionService->connect($connection, [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'business_id' => $tokens['business_id'],
                'token_expires_at' => $tokens['token_expires_at'],
                'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
                'scope' => $tokens['scope'],
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            Log::info('TikTok account connected successfully', [
                'connection_id' => $connectionId,
                'business_id' => $tokens['business_id'],
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=success&message=' . urlencode('TikTok account connected successfully!'));

        } catch (\Throwable $th) {
            Log::error('Error processing TikTok callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return redirect(config('app.frontend_url') . '/oauth/result' . '?status=error&message=' . urlencode('Failed to connect TikTok account: ' . $th->getMessage()));
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

            // Idempotency: Meta retries the same signed_request (same user_id +
            // issued_at) on failure/timeout. If we already processed it, return
            // the original confirmation without wiping data again.
            $issuedAt = $data['issued_at'] ?? null;
            $dedupeKey = $issuedAt !== null
                ? hash('sha256', $instagramUserId . '|' . $issuedAt)
                : null;

            if ($dedupeKey !== null) {
                $existing = DB::table('instagram_deletion_logs')
                    ->where('dedupe_key', $dedupeKey)
                    ->first();

                if ($existing) {
                    Log::info('Instagram data deletion: duplicate callback ignored', [
                        'instagram_user_id' => $instagramUserId,
                        'confirmation_code' => $existing->confirmation_code,
                    ]);

                    return response()->json([
                        'url' => route('instagram.deletion-status', ['code' => $existing->confirmation_code]),
                        'confirmation_code' => $existing->confirmation_code,
                    ]);
                }
            }

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
                'dedupe_key' => $dedupeKey,
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
        // The Messenger popup flow marks itself via state.channel; everything
        // else on this route is WhatsApp Embedded Signup (JSON responses).
        if ($state = $request->input('state')) {
            $stateData = json_decode(base64_decode($state), true) ?: [];

            if (($stateData['channel'] ?? null) === Channel::Messenger->value) {
                return $this->messengerCallback($request, $stateData);
            }
        }

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

        // Resolve connection_id, waba_id, phone_number_id, fb_user_id from either POST body or GET state.
        $connectionId = $request->input('connection_id');
        $wabaId = $request->input('waba_id');
        $phoneNumberId = $request->input('phone_number_id');
        // fb_user_id is the app-scoped user id (ASID) returned by FB.login. It is
        // the SAME id Meta sends in the data-deletion/deauth signed_request, so we
        // persist it to reliably match deletion requests back to this connection.
        $fbUserId = $request->input('fb_user_id');
        // Coexistence: the frontend launched Embedded Signup with
        // featureType=whatsapp_business_app_onboarding (number stays active on
        // the WhatsApp Business App). Meta's is_on_biz_app field is checked
        // later as the authoritative signal; this flag covers the race where
        // the field hasn't flipped yet right after onboarding.
        $isCoexistence = filter_var($request->input('is_coexistence', false), FILTER_VALIDATE_BOOLEAN);
        // Migration: the number is live on another BSP (Whaticket, 360dialog, …)
        // and the business is moving it here. Embedded Signup drives the move
        // itself — there is no separate API and no cooperation from the losing
        // provider — but Meta lands the number on the new WABA *unregistered*,
        // so this flag exists to stop the post-connect registration from being
        // skipped. See handleWhatsAppCallback(). No Meta field reports "this
        // number just arrived from elsewhere", hence a caller-supplied flag.
        $isMigration = filter_var($request->input('is_migration', false), FILTER_VALIDATE_BOOLEAN);

        if (!$connectionId && $state = $request->input('state')) {
            $stateData = json_decode(base64_decode($state), true) ?: [];
            $connectionId = $stateData['connection_id'] ?? null;
            $wabaId = $wabaId ?: ($stateData['waba_id'] ?? null);
            $phoneNumberId = $phoneNumberId ?: ($stateData['phone_number_id'] ?? null);
            $fbUserId = $fbUserId ?: ($stateData['fb_user_id'] ?? null);
            $isCoexistence = $isCoexistence || !empty($stateData['is_coexistence']);
            $isMigration = $isMigration || !empty($stateData['is_migration']);
        }

        if (!$connectionId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing connection_id',
            ], 400);
        }

        try {
            $connection = Connection::findOrFail($connectionId);

            // No redirect_uri: the code comes from WhatsApp Embedded Signup (FB.login
            // with response_type=code), which is not issued against a redirect URI.
            // Sending one here would make Meta reject the exchange as a mismatch.
            $tokenRequestData = [
                'client_id' => FacebookConfig::appId(),
                'client_secret' => FacebookConfig::appSecret(),
                'code' => $code,
            ];

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

            return $this->handleWhatsAppCallback($connection, $accessToken, $wabaId, $phoneNumberId, $fbUserId, $isCoexistence, $isMigration);

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

    private function handleWhatsAppCallback(Connection $connection, string $accessToken, ?string $wabaId = null, ?string $phoneNumberId = null, ?string $fbUserId = null, bool $isCoexistence = false, bool $isMigration = false)
    {
        try {
            // The WABA id comes from the frontend WA_EMBEDDED_SIGNUP "FINISH"
            // event and is authoritative for the connection. We no longer call
            // /me/businesses to enumerate/resolve it: that endpoint requires the
            // `business_management` permission, which the Embedded Signup
            // SYSTEM_USER token does not hold (it returned (#100) Missing
            // Permission on every connect). The app does not need that permission.
            if (!$wabaId) {
                throw new \Exception('Could not retrieve WhatsApp Business Account ID. Frontend must send waba_id from the WA_EMBEDDED_SIGNUP "FINISH" message event.');
            }

            // Fields requested for both single-phone and list-phone lookups.
            // platform_type + code_verification_status drive the "already
            // registered on Cloud API?" decision below — without them we'd
            // re-register every time and risk PIN-mismatch failures.
            // is_on_biz_app is Meta's authoritative Coexistence signal: true
            // means the number is (also) live on the WhatsApp Business App.
            $phoneFields = 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type,is_pin_enabled,is_on_biz_app';

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
                    // A migration is expected to land here: the customer made
                    // the destination WABA and deliberately did not add a
                    // number to it, because the number they want is still
                    // live at the other provider and comes across in the next
                    // step. Keep the account and the token — without them the
                    // migration wizard has nowhere to put it — and leave the
                    // connection Pending rather than pretending it is ready.
                    if ($isMigration) {
                        $this->subscribeWabaApp((string) $wabaId, $accessToken);

                        $connection->forceFill([
                            'credentials' => array_merge($connection->credentials ?? [], [
                                'access_token' => $accessToken,
                                'business_account_id' => (string) $wabaId,
                                'fb_user_id' => $fbUserId ?: (($connection->credentials ?? [])['fb_user_id'] ?? null),
                                'token_type' => 'SYSTEM_USER',
                            ]),
                        ])->save();

                        broadcast(new ConnectionUpdated($connection->fresh()));

                        Log::info('WhatsApp migration: destination WABA ready, awaiting the number', [
                            'connection_id' => $connection->id,
                            'business_account_id' => $wabaId,
                        ]);

                        return response()->json([
                            'status' => 'success',
                            'message' => 'WhatsApp Business Account ready. Enter the number you are migrating next.',
                            'data' => $connection->fresh()->toResource(\App\Http\Resources\ConnectionResource::class),
                        ], 200);
                    }

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
            // Trust Meta over the frontend flag, but keep the flag as a
            // fallback: right after onboarding is_on_biz_app can lag.
            $isCoexistence = $isCoexistence || (bool) ($primaryPhone['is_on_biz_app'] ?? false);

            $this->subscribeWabaApp((string) $wabaId, $accessToken);

            // Decide whether to call /register. The /register endpoint is NOT
            // safely re-runnable: if the number is already registered with a
            // PIN we don't know, the call fails (133015). Skip when Meta
            // already reports the number as live on Cloud API and verified.
            // Coexistence numbers are registered by Meta during the Business
            // App onboarding flow itself (no OTP/PIN on our side) — calling
            // /register for them fails, so they always skip.
            //
            // A migrated number is the exception that breaks the heuristic. It
            // arrives already verified — it has been live on the losing BSP for
            // months — so `code_verification_status` is VERIFIED from the first
            // read, and `platform_type` may already say CLOUD_API. Skipping on
            // that evidence leaves a connection that looks perfectly healthy
            // and cannot send: registration is per-WABA, and this number has
            // never been registered on *ours*. So a migration always attempts
            // it. The call is safe to attempt — registerPhoneNumber() treats
            // "already registered" as success.
            $alreadyRegistered = $isCoexistence
                || (!$isMigration && ($platformType === 'CLOUD_API') && ($codeVerificationStatus === 'VERIFIED'));

            // Credentials may be null here — e.g. re-authorizing a connection
            // that was wiped by a Meta data-deletion callback. Normalize to an
            // array so array-offset reads below don't warn on null.
            $existingCredentials = $connection->credentials ?? [];
            $pin = $existingCredentials['pin'] ?? null;

            // Two-step verification is the one prerequisite the business has to
            // clear at their old provider, and the only one we can see. Without
            // this check Meta answers the register call with a PIN mismatch,
            // which reads like our bug; named here, it is a two-minute fix in
            // the other provider's WhatsApp Manager.
            if ($isMigration && $isPinEnabled && !$pin) {
                throw new \Exception(
                    'Two-step verification is still enabled on this number. '
                    . 'Disable it in your current provider\'s WhatsApp Manager (Settings → Two-step verification), '
                    . 'then run the migration again.'
                );
            }

            if ($alreadyRegistered) {
                Log::info('Phone number already registered on Cloud API; skipping /register', [
                    'phone_number_id' => $phoneNumberId,
                    'platform_type' => $platformType,
                    'code_verification_status' => $codeVerificationStatus,
                    'is_pin_enabled' => $isPinEnabled,
                    'is_coexistence' => $isCoexistence,
                ]);
            } else {
                // Use stored PIN if we have one (e.g. previous attempt), else mint a new one.
                $pin = $pin ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $this->registerPhoneNumber((string) $phoneNumberId, $accessToken, $pin, $isMigration);
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
                // business_id/business_name previously came from /me/businesses
                // (business_management). That permission is no longer used, so
                // these stay null. business_account_id (the WABA id) is the field
                // the app actually relies on.
                'business_id' => null,
                'business_name' => null,
                'phone_number_id' => (string) $phoneNumberId,
                'display_phone_number' => $displayPhoneNumber,
                'verified_name' => $verifiedName,
                'quality_rating' => $qualityRating,
                'pin' => $pin,
                // App-scoped user id from FB.login — used to match Meta
                // deauth/data-deletion signed_requests back to this connection.
                'fb_user_id' => $fbUserId ?: ($existingCredentials['fb_user_id'] ?? null),
                'token_type' => 'SYSTEM_USER',
                'token_expires_at' => null,
                'platform_type' => $platformType,
                'is_coexistence' => $isCoexistence,
                // Kept so support can tell a migrated number from a fresh one
                // months later: the two behave identically afterwards, but only
                // a migrated one has templates and a quality rating that were
                // rebuilt by Meta rather than earned here.
                'migrated_from_bsp' => $isMigration ?: null,
                'migrated_at' => $isMigration ? now()->toIso8601String() : null,
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            // Coexistence 24h window: request contact + history sync from the
            // Business App right away (webhooks deliver the data async).
            if ($isCoexistence) {
                SyncCoexistenceSmbData::dispatchIfPending($connection->fresh());
            }

            Log::info('WhatsApp account connected successfully', [
                'connection_id' => $connection->id,
                'business_account_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $displayPhoneNumber,
                'is_coexistence' => $isCoexistence,
                'is_migration' => $isMigration,
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
     * Messenger leg of the shared /oauth/facebook/callback route. This is a
     * browser redirect flow (OAuth popup, like Instagram/TikTok), so every
     * outcome redirects to the SPA's /oauth/result page — never JSON.
     */
    private function messengerCallback(Request $request, array $stateData)
    {
        $resultUrl = config('app.frontend_url') . '/oauth/result';

        if ($error = $request->input('error')) {
            Log::error('Messenger OAuth error', [
                'error' => $error,
                'error_reason' => $request->input('error_reason'),
                'error_description' => $request->input('error_description'),
            ]);

            return redirect($resultUrl . '?status=error&message=' . urlencode('Facebook OAuth error: ' . ($request->input('error_description') ?: $error)));
        }

        $code = $request->input('code');
        $connectionId = $stateData['connection_id'] ?? null;

        if (!$code || !$connectionId) {
            Log::error('Missing code or connection_id in Messenger callback');

            return redirect($resultUrl . '?status=error&message=' . urlencode('Invalid Facebook callback: missing code or state parameter'));
        }

        try {
            $connection = Connection::findOrFail($connectionId);

            // Unlike Embedded Signup, this code was issued against a redirect
            // URI, so the exchange must repeat the exact same one.
            $response = Http::asForm()->post('https://graph.facebook.com/v25.0/oauth/access_token', [
                'client_id' => FacebookConfig::appId(),
                'client_secret' => FacebookConfig::appSecret(),
                'redirect_uri' => FacebookConfig::redirectUri(),
                'code' => $code,
            ]);

            if (!$response->successful()) {
                Log::error('Failed to exchange Messenger code for token', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);
                throw new \Exception('Failed to obtain access token: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
            }

            $accessToken = $response->json()['access_token'] ?? null;
            if (!$accessToken) {
                throw new \Exception('Invalid response from Facebook OAuth.');
            }

            return $this->handleMessengerCallback($connection, $accessToken);
        } catch (\Throwable $th) {
            Log::error('Error processing Messenger callback', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return redirect($resultUrl . '?status=error&message=' . urlencode('Failed to connect Facebook Page: ' . $th->getMessage()));
        }
    }

    private function handleMessengerCallback(Connection $connection, string $accessToken)
    {
        $resultUrl = config('app.frontend_url') . '/oauth/result';

        // Long-lived user token (~60 days). Page tokens minted from it do not
        // expire, so nothing needs a refresh scheduler afterwards.
        $exchange = Http::get('https://graph.facebook.com/v25.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => FacebookConfig::appId(),
            'client_secret' => FacebookConfig::appSecret(),
            'fb_exchange_token' => $accessToken,
        ]);

        if ($exchange->successful()) {
            $userToken = $exchange->json()['access_token'] ?? $accessToken;
        } else {
            Log::warning('Failed to get long-lived Facebook user token, using short-lived token', [
                'response' => $exchange->json(),
            ]);
            $userToken = $accessToken;
        }

        // App-scoped user id (ASID) — the id Meta sends in deauth/data-deletion
        // signed_requests, persisted to match those callbacks back to this row.
        $me = Http::get('https://graph.facebook.com/v25.0/me', [
            'fields' => 'id,name',
            'access_token' => $userToken,
        ]);
        $fbUserId = $me->successful() ? ($me->json()['id'] ?? null) : null;

        // Pages this user granted access to (pages_show_list).
        $pagesResponse = Http::get('https://graph.facebook.com/v25.0/me/accounts', [
            'fields' => 'id,name,access_token',
            'access_token' => $userToken,
        ]);

        if (!$pagesResponse->successful()) {
            throw new \Exception('Failed to list Facebook Pages: ' . ($pagesResponse->json()['error']['message'] ?? 'Unknown error'));
        }

        $pages = $pagesResponse->json()['data'] ?? [];

        if (empty($pages)) {
            // 200 + empty data means the token is fine but zero Pages were
            // opted in during login (granular permissions), or the FB user has
            // no role on the app while it is in development mode. Snapshot the
            // granted permissions so the log tells those cases apart.
            $permissions = Http::get('https://graph.facebook.com/v25.0/me/permissions', [
                'access_token' => $userToken,
            ]);

            // debug_token's granular_scopes lists the exact Page ids inside the
            // token's pages_show_list scope — empty target_ids means the opt-in
            // never registered, populated target_ids with an empty /me/accounts
            // means Graph is filtering the response (app role / access level).
            $debugToken = Http::get('https://graph.facebook.com/v25.0/debug_token', [
                'input_token' => $userToken,
                'access_token' => FacebookConfig::appId() . '|' . FacebookConfig::appSecret(),
            ]);

            $debugData = $debugToken->json()['data'] ?? [];

            Log::warning('Messenger OAuth: /me/accounts returned no pages', [
                'connection_id' => $connection->id,
                'me' => $me->json(),
                'accounts_response' => $pagesResponse->json(),
                'permissions' => $permissions->json(),
                'debug_token' => $debugData ?: $debugToken->json(),
            ]);

            // /me/accounts is a user-centric edge, but Facebook Login for
            // Business hands the Pages over through a business portfolio — a
            // token can carry the Page ids in granular_scopes (proof the grant
            // landed) and still get an empty list back here. Ask for the very
            // same Pages the other two ways before giving up.
            $pages = $this->messengerFallbackPages($connection, $userToken, $debugData);
        }

        if (empty($pages)) {
            return redirect($resultUrl . '?status=error&message=' . urlencode('Facebook authorized the app but returned no usable Page. If the Pages were selected on the Facebook screen, the app most likely still has Standard Access for Page permissions — it can then only read Pages owned by the business portfolio that owns the app. Check the server log for the exact Graph response.'));
        }

        if (count($pages) === 1) {
            $page = $pages[0];

            $this->connectionService->connect($connection, [
                'page_id' => (string) $page['id'],
                'page_name' => $page['name'] ?? null,
                'access_token' => $page['access_token'] ?? null,
                'user_access_token' => $userToken,
                'fb_user_id' => $fbUserId,
            ]);

            broadcast(new ConnectionUpdated($connection->fresh()));

            Log::info('Messenger page connected successfully', [
                'connection_id' => $connection->id,
                'page_id' => $page['id'],
            ]);

            return redirect($resultUrl . '?status=success&message=' . urlencode('Facebook Page connected successfully!'));
        }

        // Multiple Pages: stash the choice list. The SPA renders a picker and
        // finishes via POST /connections/{id}/connect {page_id}.
        //
        // The page access token that came with the list is kept, not thrown
        // away: re-fetching it at connect time (GET /{page_id}?fields=access_token)
        // is refused whenever the app cannot read the Page node directly — the
        // exact situation this login flow exists to get around. These tokens
        // live only until a Page is picked; connect() replaces credentials
        // wholesale, taking the unpicked ones with it.
        $connection->update([
            'status' => Status::Pending,
            'credentials' => [
                'user_access_token' => $userToken,
                'fb_user_id' => $fbUserId,
                'pending_pages' => collect($pages)
                    ->map(fn ($page) => [
                        'id' => (string) $page['id'],
                        'name' => $page['name'] ?? '',
                        'access_token' => $page['access_token'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);

        broadcast(new ConnectionUpdated($connection->fresh()));

        Log::info('Messenger OAuth authorized; awaiting page selection', [
            'connection_id' => $connection->id,
            'page_count' => count($pages),
            // A Page listed without a token can only be connected by the
            // re-fetch, so this number is what says whether connect will work.
            'pages_with_token' => collect($pages)->filter(fn ($page) => !empty($page['access_token']))->count(),
        ]);

        return redirect($resultUrl . '?status=success&message=' . urlencode('Authorized! Now choose which Page to connect.'));
    }

    /**
     * Second and third attempts at the Page list, run only once /me/accounts
     * has already come back empty.
     *
     * Both read the exact Page ids the token itself proves were granted
     * (debug_token's granular_scopes), so neither can widen access beyond what
     * the user picked in the login dialog — a Page that never made it into the
     * token is never connectable through here.
     *
     * @param  array<string, mixed>  $debugData  debug_token's `data` payload
     * @return array<int, array<string, mixed>>  Pages carrying a page access token
     */
    private function messengerFallbackPages(Connection $connection, string $userToken, array $debugData): array
    {
        $granted = collect($debugData['granular_scopes'] ?? [])
            ->whereIn('scope', ['pages_show_list', 'pages_messaging'])
            ->flatMap(fn ($scope) => $scope['target_ids'] ?? [])
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $pages = [];
        $attempts = [];

        // (1) Straight node read per granted Page id. The edge can be filtered
        // while the node itself still answers.
        //
        // Ask for the token ALONE. `name` needs pages_read_engagement, which
        // this login configuration does not request, and Graph rejects the
        // whole request when a single field is disallowed — asking for the
        // name too would throw away the one field that actually matters.
        foreach ($granted as $pageId) {
            $response = Http::get("https://graph.facebook.com/v25.0/{$pageId}", [
                'fields' => 'access_token',
                'access_token' => $userToken,
            ]);

            $attempts["page:{$pageId}"] = $response->json();

            $pageToken = $response->successful() ? ($response->json()['access_token'] ?? null) : null;

            if (!$pageToken) {
                continue;
            }

            // A Page's own token can always read that Page's name; the user
            // token cannot, without the permission above.
            $named = Http::get('https://graph.facebook.com/v25.0/me', [
                'fields' => 'id,name',
                'access_token' => $pageToken,
            ]);

            $pages[] = [
                'id' => (string) $pageId,
                'name' => $named->successful() ? ($named->json()['name'] ?? null) : null,
                'access_token' => $pageToken,
            ];
        }

        // (2) The business edges — where Login for Business actually files the
        // assets it just handed over. Needs business_management, which that
        // login configuration grants alongside the page permissions.
        if (empty($pages)) {
            $businesses = Http::get('https://graph.facebook.com/v25.0/me/businesses', [
                'fields' => 'id,name',
                'access_token' => $userToken,
            ]);

            $attempts['me/businesses'] = $businesses->json();

            foreach ($businesses->json()['data'] ?? [] as $business) {
                // Which portfolio claims this app decides everything above:
                // under Standard Access only that portfolio's Pages are
                // readable. Nothing here reads it back to us, so ask.
                foreach (['owned_apps', 'client_apps'] as $edge) {
                    $attempts["{$business['id']}/{$edge}"] = Http::get("https://graph.facebook.com/v25.0/{$business['id']}/{$edge}", [
                        'fields' => 'id,name',
                        'access_token' => $userToken,
                    ])->json();
                }

                foreach (['owned_pages', 'client_pages'] as $edge) {
                    $response = Http::get("https://graph.facebook.com/v25.0/{$business['id']}/{$edge}", [
                        'fields' => 'id,name,access_token',
                        'access_token' => $userToken,
                    ]);

                    $attempts["{$business['id']}/{$edge}"] = $response->json();

                    foreach ($response->json()['data'] ?? [] as $page) {
                        if ($granted->contains((string) ($page['id'] ?? '')) && !empty($page['access_token'])) {
                            $pages[] = $page;
                        }
                    }
                }
            }
        }

        $pages = collect($pages)->unique('id')->values()->all();

        Log::warning('Messenger OAuth: fallback page discovery', [
            'connection_id' => $connection->id,
            'granted_page_ids' => $granted->all(),
            'recovered_page_ids' => collect($pages)->pluck('id')->all(),
            'attempts' => $attempts,
        ]);

        return $pages;
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
     *
     * $isMigration only changes what a failure is allowed to say. Every way
     * this call fails for a number arriving from another BSP has a fix the
     * business can carry out themselves, and none of them are visible in
     * Meta's raw error text.
     */
    private function registerPhoneNumber(string $phoneNumberId, string $accessToken, string $pin, bool $isMigration = false): void
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
            'is_migration' => $isMigration,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        if ($isMigration && $hint = self::migrationRegisterHint($code)) {
            throw new \Exception($hint);
        }

        throw new \Exception('Failed to register phone number: ' . ($message ?: 'Unknown error') . " (code {$code}, subcode {$subcode})");
    }

    /**
     * Plain-language cause for a registration that failed while migrating a
     * number in from another provider.
     *
     * Meta reports these as bare numeric codes with text written for whoever
     * wrote the integration, not for the business owner who has to act. Each
     * one below is something they can fix without us, and saying which one it
     * is turns a support ticket into a two-minute task. Codes we cannot
     * translate return null and keep Meta's own message — a wrong guess is
     * worse than a technical string.
     */
    private static function migrationRegisterHint(int $code): ?string
    {
        return match ($code) {
            // 133005: registered elsewhere under a PIN we do not know.
            133005 => 'This number still has two-step verification enabled at your current provider. '
                . 'Ask them to disable it (or turn it off yourself in WhatsApp Manager → Settings → Two-step verification), '
                . 'then run the migration again.',
            // 133006: the number was never verified, so it cannot be moved.
            133006 => 'This number has not completed phone verification. '
                . 'Finish verification in WhatsApp Manager before migrating it.',
            // 133016: too many registration attempts in a short window.
            133016 => 'Meta is rate-limiting registration attempts for this number. '
                . 'Wait a few minutes and try the migration again.',
            default => null,
        };
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

            // Primary: match on the app-scoped user id (ASID) stored at connect.
            // Fallback (legacy connections without it): ask Meta which stored
            // tokens are now invalid and deauthorize those.
            Log::info('Facebook deauthorization: matching connections by fb_user_id', [
                'facebook_user_id' => $data['user_id'],
            ]);

            $deauthorized = $this->whatsAppTokenValidator->deauthorizeByFacebookUserId($data['user_id']);

            if ($deauthorized->isEmpty()) {
                // Fallback scan probes Meta once per connection. On large tenants
                // that would blow the webhook's time budget, so push it to a queue
                // and acknowledge Meta immediately.
                $candidateCount = $this->whatsAppTokenValidator->revocationScanCandidateCount();

                if ($candidateCount > self::DEAUTH_SYNC_LIMIT) {
                    DeauthorizeRevokedWhatsAppConnections::dispatch();

                    Log::info('Facebook deauthorization: large connection set, queued revoked-token scan', [
                        'facebook_user_id' => $data['user_id'],
                        'candidate_count' => $candidateCount,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Deauthorization queued for processing',
                    ]);
                }

                Log::info('Facebook deauthorization: no fb_user_id match, falling back to token validation', [
                    'facebook_user_id' => $data['user_id'],
                ]);
                $deauthorized = $this->whatsAppTokenValidator->deauthorizeRevoked();
            }

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

            // Idempotency: Meta retries the same signed_request (same user_id +
            // issued_at) on failure/timeout. If we already processed it, return
            // the original confirmation without wiping data again.
            $issuedAt = $data['issued_at'] ?? null;
            $dedupeKey = $issuedAt !== null
                ? hash('sha256', $facebookUserId . '|' . $issuedAt)
                : null;

            if ($dedupeKey !== null) {
                $existing = DB::table('facebook_deletion_logs')
                    ->where('dedupe_key', $dedupeKey)
                    ->first();

                if ($existing) {
                    Log::info('Facebook data deletion: duplicate callback ignored', [
                        'facebook_user_id' => $facebookUserId,
                        'confirmation_code' => $existing->confirmation_code,
                    ]);

                    return response()->json([
                        'url' => route('oauth.facebook.deletion-status', ['code' => $existing->confirmation_code]),
                        'confirmation_code' => $existing->confirmation_code,
                    ]);
                }
            }

            $confirmationCode = hash('sha256', $facebookUserId . time() . uniqid());
            $statusUrl = route('oauth.facebook.deletion-status', ['code' => $confirmationCode]);

            Log::info('Facebook data deletion: matching connections by fb_user_id', [
                'facebook_user_id' => $facebookUserId,
                'confirmation_code' => $confirmationCode,
            ]);

            // Primary: exact match on the app-scoped user id (ASID) we stored at
            // connect time — this is the same id Meta sends here, so it deletes
            // the right connection's data deterministically.
            $stats = $this->whatsAppTokenValidator->deleteDataByFacebookUserId($facebookUserId);

            // Fallback (legacy connections with no stored fb_user_id): ask Meta
            // which stored tokens are now invalid and delete those.
            if ($stats['connections'] === 0) {
                Log::info('Facebook data deletion: no fb_user_id match, falling back to token validation', [
                    'facebook_user_id' => $facebookUserId,
                ]);
                $stats = $this->whatsAppTokenValidator->deleteRevokedData();
            }

            DB::table('facebook_deletion_logs')->insert([
                'confirmation_code' => $confirmationCode,
                'facebook_user_id' => $facebookUserId,
                'dedupe_key' => $dedupeKey,
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
     * Parse Instagram signed request. Returns null if the signature is missing,
     * malformed, or invalid — never returns the payload of an unverified request.
     */
    private function parseSignedRequest(string $signedRequest): ?array
    {
        return $this->verifyAndDecodeSignedRequest(
            $signedRequest,
            (string) InstagramConfig::clientSecret(),
            'instagram'
        );
    }

    /**
     * Parse Facebook signed request (for WhatsApp & Messenger). Returns null
     * if the signature is missing, malformed, or invalid.
     */
    private function parseFacebookSignedRequest(string $signedRequest): ?array
    {
        return $this->verifyAndDecodeSignedRequest(
            $signedRequest,
            (string) FacebookConfig::appSecret(),
            'facebook'
        );
    }

    /**
     * Verify a Meta signed_request and return its decoded payload, or null on
     * any failure. Hard-fails on signature mismatch — Meta App Review and basic
     * security require that we NEVER act on an unverified payload (an attacker
     * could otherwise craft a signed_request with an arbitrary user_id and
     * trigger deauth/data-deletion against any account).
     *
     * Uses hash_equals for timing-safe comparison and validates the declared
     * algorithm matches what we compute.
     */
    private function verifyAndDecodeSignedRequest(string $signedRequest, string $secret, string $provider): ?array
    {
        if ($secret === '') {
            Log::error('Signed request: app secret not configured', ['provider' => $provider]);
            return null;
        }

        $parts = explode('.', $signedRequest, 2);
        if (count($parts) !== 2) {
            Log::warning('Signed request: malformed (missing "." separator)', ['provider' => $provider]);
            return null;
        }
        [$encodedSig, $payload] = $parts;

        $sig = base64_decode(strtr($encodedSig, '-_', '+/'), true);
        $jsonPayload = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($sig === false || $jsonPayload === false) {
            Log::warning('Signed request: base64 decode failed', ['provider' => $provider]);
            return null;
        }

        $data = json_decode($jsonPayload, true);
        if (!is_array($data)) {
            Log::warning('Signed request: payload not a JSON object', ['provider' => $provider]);
            return null;
        }

        $algorithm = strtoupper((string) ($data['algorithm'] ?? ''));
        if ($algorithm !== 'HMAC-SHA256') {
            Log::warning('Signed request: unsupported algorithm', [
                'provider' => $provider,
                'algorithm' => $algorithm,
            ]);
            return null;
        }

        $expectedSig = hash_hmac('sha256', $payload, $secret, true);

        if (!hash_equals($expectedSig, $sig)) {
            Log::error('Signed request: signature verification failed — REJECTING request', [
                'provider' => $provider,
            ]);
            return null;
        }

        return $data;
    }
}
