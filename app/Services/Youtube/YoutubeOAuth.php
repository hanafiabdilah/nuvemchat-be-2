<?php

namespace App\Services\Youtube;

use App\Exceptions\YoutubeApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google's OAuth 2.0 endpoints, as the YouTube connect flow uses them.
 *
 * The `state` is a random key into the cache, not an encoded payload. The
 * inbox channels encode the connection id into a base64 state, which anyone can
 * forge; here the state is what carries *which workspace* the channel lands in,
 * so it must be something only we could have handed out — and it is consumed
 * on first use, so a replayed callback attaches nothing.
 */
class YoutubeOAuth
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Long enough to pick a Google account and a channel; short enough not to linger. */
    private const STATE_TTL_MINUTES = 15;

    /**
     * The consent URL for one workspace and the person connecting.
     *
     * `access_type=offline` + `prompt=consent` together are what make Google
     * return a refresh token every time — without `prompt=consent`, a Google
     * account that already granted this app gets an access token only, and the
     * channel stops working an hour later.
     */
    public function authorizeUrl(int $tenantId, int $userId): string
    {
        $state = Str::random(48);

        Cache::put($this->stateKey($state), [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => YoutubeConfig::clientId(),
            'redirect_uri' => YoutubeConfig::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', YoutubeConfig::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Who started this authorization, or null when the state is unknown or
     * already used.
     *
     * @return array{tenant_id: int, user_id: int}|null
     */
    public function consumeState(?string $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $data = Cache::pull($this->stateKey($state));

        return is_array($data) ? $data : null;
    }

    /**
     * Trade the authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, token_expires_at: string, scope: string}
     */
    public function exchangeCode(string $code): array
    {
        $body = $this->token([
            'code' => $code,
            'client_id' => YoutubeConfig::clientId(),
            'client_secret' => YoutubeConfig::clientSecret(),
            'redirect_uri' => YoutubeConfig::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'token_expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 3600))->toIso8601String(),
            'scope' => (string) ($body['scope'] ?? ''),
        ];
    }

    /**
     * A fresh access token from a refresh token.
     *
     * Google does not rotate the refresh token here, so a missing one in the
     * answer means "keep the one you have", not "it is gone".
     *
     * @return array{access_token: string, token_expires_at: string}
     */
    public function refresh(string $refreshToken): array
    {
        $body = $this->token([
            'refresh_token' => $refreshToken,
            'client_id' => YoutubeConfig::clientId(),
            'client_secret' => YoutubeConfig::clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        return [
            'access_token' => (string) $body['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 3600))->toIso8601String(),
        ];
    }

    /**
     * Revoke the grant. Best-effort: a token Google already forgot is the
     * outcome we wanted anyway.
     */
    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable) {
            // The local disconnect proceeds regardless.
        }
    }

    /** Whether everything the feature needs was granted (the consent screen lets people untick). */
    public static function grantedAll(string $scope): bool
    {
        $granted = preg_split('/\s+/', trim($scope)) ?: [];

        return array_diff(YoutubeConfig::SCOPES, $granted) === [];
    }

    private function token(array $params): array
    {
        if (! YoutubeConfig::isConfigured()) {
            throw new YoutubeApiException('The platform has no Google OAuth client configured.', 'not_configured', 503);
        }

        $response = Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, $params);
        $body = $response->json() ?? [];

        if ($response->failed() || empty($body['access_token'])) {
            throw YoutubeApiException::fromResponse($body, $response->status() ?: 502);
        }

        return $body;
    }

    private function stateKey(string $state): string
    {
        return 'youtube:oauth:'.hash('sha256', $state);
    }
}
