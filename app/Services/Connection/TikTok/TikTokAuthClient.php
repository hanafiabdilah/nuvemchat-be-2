<?php

namespace App\Services\Connection\TikTok;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client over the TikTok Business Messaging OAuth + webhook endpoints.
 * Every response uses the { code, message, data } envelope where code 0 means
 * success — non-zero codes arrive with HTTP 200, so both must be checked.
 */
class TikTokAuthClient
{
    public const REQUIRED_SCOPES = [
        'user.info.basic',
        'user.info.username',
        'user.info.stats',
        'user.info.profile',
        'user.account.type',
        'user.insights',
        'message.list.read',
        'message.list.send',
        'message.list.manage',
    ];

    public const API_BASE = TikTokConfig::BASE_URL . '/open_api/v1.3';

    public static function authorizeUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_key' => TikTokConfig::appId(),
            'redirect_uri' => TikTokConfig::redirectUri(),
            'scope' => implode(',', self::REQUIRED_SCOPES),
            'state' => $state,
        ]);

        return 'https://www.tiktok.com/v2/auth/authorize?' . $query;
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
