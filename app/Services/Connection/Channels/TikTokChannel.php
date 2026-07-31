<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use App\Services\Connection\TikTok\TikTokAuthClient;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TikTokChannel implements ChannelInterface
{
    /**
     * Store the tokens obtained by the OAuth callback. $data comes from
     * TikTokAuthClient::exchangeCode(), never from user input.
     */
    public function connect(Connection $connection, array $data): void
    {
        validator($data, [
            'access_token' => ['required', 'string'],
            'refresh_token' => ['required', 'string'],
            'business_id' => ['required', 'string'],
            'token_expires_at' => ['required', 'string'],
            'refresh_token_expires_at' => ['required', 'string'],
            'scope' => ['nullable', 'string'],
        ])->validate();

        if (Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::TikTok)
            ->where('credentials->business_id', $data['business_id'])
            ->exists()) {
            throw ValidationException::withMessages(['business_id' => 'This TikTok account is already connected.']);
        }

        try {
            // Verify the token and pick up the account identity in one call.
            $account = TikTokAuthClient::businessAccountDetails($data['business_id'], $data['access_token']);

            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'business_id' => $data['business_id'],
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'token_expires_at' => $data['token_expires_at'],
                    'refresh_token_expires_at' => $data['refresh_token_expires_at'],
                    'scope' => $data['scope'] ?? null,
                    'username' => $account['username'] ?? null,
                    'display_name' => $account['display_name'] ?? null,
                    'profile_image' => $account['profile_image'] ?? null,
                ],
            ]);

            $this->registerWebhook($connection);
        } catch (ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('Failed to connect TikTok account', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
            throw new Exception('An error occurred while connecting to TikTok: ' . $th->getMessage());
        }
    }

    public function disconnect(Connection $connection): void
    {
        // The tt_user OAuth API exposes no revoke endpoint — deauthorize locally;
        // the user can revoke the app from their TikTok settings.
        $connection->update([
            'status' => Status::Inactive,
            'credentials' => null,
        ]);
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $accessToken = $this->ensureValidAccessToken($connection);

            TikTokAuthClient::businessAccountDetails(
                $connection->credentials['business_id'] ?? '',
                $accessToken
            );

            $connection->update([
                'status' => Status::Active,
            ]);
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            throw new ConnectionException('Invalid TikTok session. Please reconnect your account.', 400);
        }
    }

    /**
     * Access tokens only live ~24h. Returns a token valid for at least the
     * next 5 minutes, refreshing (and persisting) when needed — the entry
     * point for everything that calls the TikTok API on a connection.
     */
    public function ensureValidAccessToken(Connection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $accessToken = $credentials['access_token'] ?? null;
        $expiresAt = ! empty($credentials['token_expires_at'])
            ? Carbon::parse($credentials['token_expires_at'])
            : null;

        if ($accessToken && $expiresAt !== null && $expiresAt->isAfter(now()->addMinutes(5))) {
            return $accessToken;
        }

        $this->refreshToken($connection);

        return $connection->credentials['access_token'];
    }

    public function refreshToken(Connection $connection): void
    {
        $credentials = $connection->credentials ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;
        $refreshExpiresAt = ! empty($credentials['refresh_token_expires_at'])
            ? Carbon::parse($credentials['refresh_token_expires_at'])
            : null;

        if (! $refreshToken || ($refreshExpiresAt !== null && $refreshExpiresAt->isPast())) {
            throw new Exception('TikTok refresh token expired. Please reconnect the account.');
        }

        $tokens = TikTokAuthClient::refreshAccessToken($refreshToken);

        if (empty($tokens['access_token'])) {
            throw new Exception('Invalid response from TikTok token refresh.');
        }

        $connection->update([
            'credentials' => array_merge($credentials, [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?: $refreshToken,
                'token_expires_at' => $tokens['token_expires_at'],
                'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
            ]),
        ]);

        Log::info('Successfully refreshed TikTok token', [
            'connection_id' => $connection->id,
            'expires_at' => $tokens['token_expires_at'],
        ]);
    }

    /**
     * Point the app-level DIRECT_MESSAGE webhook at our endpoint. Non-fatal:
     * the callback URL is app-wide (shared by every TikTok connection), so a
     * failure here must not undo an otherwise valid OAuth connect — but it is
     * logged as an error because without it no inbound DM ever arrives.
     */
    private function registerWebhook(Connection $connection): void
    {
        try {
            TikTokAuthClient::updateWebhookCallback(url('/webhook/tiktok'));

            Log::info('Successfully registered TikTok webhook callback', [
                'connection_id' => $connection->id,
            ]);
        } catch (\Throwable $th) {
            Log::error('Failed to register TikTok webhook callback', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
