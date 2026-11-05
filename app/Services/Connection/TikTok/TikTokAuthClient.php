<?php

namespace App\Services\Connection\TikTok;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client over the TikTok account-holder OAuth + webhook endpoints.
 * Every response uses the { code, message, data } envelope where code 0 means
 * success — non-zero codes arrive with HTTP 200, so both must be checked.
 */
class TikTokAuthClient
{
    /**
     * The account itself: who it is. Part of the Accounts API, so granted to
     * every app approved for "TikTok accounts", and always asked for.
     */
    public const ACCOUNT_SCOPES = [
        'user.info.basic',
        'user.info.username',
        'user.info.stats',
        'user.info.profile',
        'user.account.type',
        'user.insights',
    ];

    /**
     * Direct messages (Business Messaging). Requested only when the Back
     * Office says TikTok has granted them (TikTokConfig::messagingEnabled):
     * they come from a separate review, not from the scope picker.
     */
    public const MESSAGE_SCOPES = [
        'message.list.read',
        'message.list.send',
        'message.list.manage',
    ];

    /**
     * Posting and comment moderation (the Accounts API). Requested only when
     * the Back Office says the app holds them (TikTokConfig::postsEnabled) and
     * never required at callback: an account without them still runs its
     * inbox, and the Posts page asks it to reconnect.
     */
    public const POST_SCOPES = [
        'video.list',
        'video.publish',
        'comment.list',
        'comment.list.manage',
    ];

    public const API_BASE = TikTokConfig::BASE_URL . '/open_api/v1.3';

    public static function authorizeUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_key' => TikTokConfig::appId(),
            'redirect_uri' => TikTokConfig::redirectUri(),
            'scope' => implode(',', self::requestedScopes()),
            'state' => $state,
        ]);

        return 'https://www.tiktok.com/v2/auth/authorize?' . $query;
    }

    /**
     * What Connect asks TikTok for: only what the app holds. One scope the app
     * was never granted and TikTok refuses the whole consent screen.
     *
     * @return list<string>
     */
    public static function requestedScopes(): array
    {
        return array_values(array_unique([
            ...self::requiredScopes(),
            ...(TikTokConfig::postsEnabled() ? self::POST_SCOPES : []),
        ]));
    }

    /**
     * What the account must grant for the connection to be accepted. Post
     * scopes are not here (see POST_SCOPES); message scopes are, once asked
     * for — an inbox without them fails at every send and every webhook.
     *
     * @return list<string>
     */
    public static function requiredScopes(): array
    {
        return TikTokConfig::messagingEnabled()
            ? [...self::ACCOUNT_SCOPES, ...self::MESSAGE_SCOPES]
            : self::ACCOUNT_SCOPES;
    }

    /** Whether Connect currently gives an account anything to do here. */
    public static function hasAnythingToRequest(): bool
    {
        return TikTokConfig::messagingEnabled() || TikTokConfig::postsEnabled();
    }

    /**
     * Exchange the OAuth code for tokens. Access tokens live ~24h, refresh
     * tokens ~30 days; `open_id` identifies the TikTok Business Account and is
     * stored as `business_id` in the connection credentials.
     */
    public static function exchangeCode(string $code): array
    {
        $data = self::request('post', '/tt_user/oauth2/token/', [
            'client_id' => TikTokConfig::appId(),
            'client_secret' => TikTokConfig::appSecret(),
            'grant_type' => 'authorization_code',
            'auth_code' => $code,
            'redirect_uri' => TikTokConfig::redirectUri(),
        ], 'Failed to obtain TikTok access token');

        return self::tokenPayload($data) + [
            'business_id' => $data['open_id'] ?? null,
            'scope' => $data['scope'] ?? null,
        ];
    }

    public static function refreshAccessToken(string $refreshToken): array
    {
        $data = self::request('post', '/tt_user/oauth2/refresh_token/', [
            'client_id' => TikTokConfig::appId(),
            'client_secret' => TikTokConfig::appSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], 'Failed to refresh TikTok access token');

        return self::tokenPayload($data);
    }

    /**
     * Point the app-level DIRECT_MESSAGE webhook at our endpoint. TikTok keeps
     * one callback URL per app, so re-registering the same URL is idempotent.
     */
    public static function updateWebhookCallback(string $callbackUrl): array
    {
        return self::request('post', '/business/webhook/update/', [
            'app_id' => TikTokConfig::appId(),
            'secret' => TikTokConfig::appSecret(),
            'event_type' => 'DIRECT_MESSAGE',
            'callback_url' => $callbackUrl,
        ], 'Failed to update TikTok webhook callback');
    }

    public static function businessAccountDetails(string $businessId, string $accessToken): array
    {
        $data = self::request('get', '/business/get/', [
            'business_id' => $businessId,
            'fields' => json_encode(['username', 'display_name', 'profile_image']),
        ], 'Failed to fetch TikTok account details', $accessToken);

        return [
            'username' => $data['username'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'profile_image' => $data['profile_image'] ?? null,
        ];
    }

    private static function tokenPayload(array $data): array
    {
        return [
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 86400))->toDateTimeString(),
            'refresh_token_expires_at' => now()->addSeconds((int) ($data['refresh_token_expires_in'] ?? 2592000))->toDateTimeString(),
        ];
    }

    private static function request(string $method, string $path, array $payload, string $errorPrefix, ?string $accessToken = null): array
    {
        $pending = Http::acceptJson();

        if ($accessToken !== null) {
            $pending = $pending->withHeaders(['Access-Token' => $accessToken]);
        }

        $response = $method === 'get'
            ? $pending->get(self::API_BASE . $path, $payload)
            : $pending->asJson()->post(self::API_BASE . $path, $payload);

        $json = $response->json() ?? [];

        if (! $response->successful() || ($json['code'] ?? -1) !== 0) {
            Log::error($errorPrefix, [
                'status' => $response->status(),
                'body' => $json,
            ]);

            throw new Exception($errorPrefix . ': ' . ($json['message'] ?? 'HTTP ' . $response->status()));
        }

        return $json['data'] ?? [];
    }
}
