<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InstagramChannel implements ChannelInterface
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function connect(Connection $connection, array $data): void
    {
        validator($data, [
            'access_token' => ['required', 'string'],
            'page_id' => ['required', 'string'],
            'instagram_account_id' => ['required', 'string'],
            'user_id' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
        ])->validate();

        if(Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::Instagram)
            ->where('credentials->instagram_account_id', $data['instagram_account_id'])
            ->exists()) {
            throw ValidationException::withMessages(['instagram_account_id' => 'This Instagram account is already connected.']);
        }

        try {
            // Verify the access token and get account info using Instagram Graph API
            $response = Http::get('https://graph.instagram.com/v25.0/me', [
                'fields' => 'id,name,username,profile_picture_url',
                'access_token' => $data['access_token'],
            ]);

            if (!$response->successful()) {
                Log::error('Invalid Instagram access token', [
                    'response' => $response->json(),
                ]);
                throw new Exception('Invalid Instagram access token provided.');
            }

            $accountInfo = $response->json();

            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'access_token' => $data['access_token'],
                    'page_id' => $data['page_id'],
                    'instagram_account_id' => $data['instagram_account_id'],
                    'user_id' => $data['user_id'] ?? null,
                    'username' => $accountInfo['username'] ?? $data['username'] ?? null,
                    'name' => $accountInfo['name'] ?? null,
                ],
            ]);

            // Subscribe to webhooks
            $this->subscribeWebhook($connection);

        } catch(ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('Failed to connect Instagram account', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
            throw new Exception('An error occurred while connecting to Instagram: ' . $th->getMessage());
        }
    }

    public function disconnect(Connection $connection): void
    {
        // IMPORTANT: Order matters!
        // 1. Unsubscribe webhook FIRST (while token is still valid)
        // 2. Then revoke access token (invalidates the token)
        // 3. Finally clear credentials

        try {
            // 1. Unsubscribe from webhooks (requires valid access token)
            $this->unsubscribeWebhook($connection);
        } catch (\Throwable $th) {
            Log::warning('Failed to unsubscribe Instagram webhook, but will continue disconnecting', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage()
            ]);
        }

        try {
            // 2. Revoke access token (removes app from Instagram permissions)
            //    This must be done AFTER unsubscribe because it invalidates the token
            $this->revokeAccessToken($connection);
        } catch (\Throwable $th) {
            Log::warning('Failed to revoke Instagram access token, but will continue disconnecting', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage()
            ]);
        }

        // 3. Clear credentials and set status to disconnected
        // This ensures user's Instagram data is removed from our system
        $connection->update([
            'status' => Status::Inactive,
            'credentials' => null,
        ]);

        Log::info('Instagram connection disconnected successfully', [
            'connection_id' => $connection->id,
        ]);
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $response = Http::get('https://graph.instagram.com/v25.0/me', [
                'fields' => 'id,username',
                'access_token' => $connection->credentials['access_token'] ?? null,
            ]);

            if ($response->successful()) {
                $connection->update([
                    'status' => Status::Active,
                ]);
            } else {
                $connection->update([
                    'status' => Status::Inactive,
                ]);

                throw new ConnectionException('Invalid Instagram access token. Please reconnect your account.', 400);
            }
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            throw new ConnectionException('An error occurred while checking the Instagram connection. Please try again later.', 500);
        }
    }

    private function subscribeWebhook(Connection $connection): void
    {
        try {
            $instagramAccountId = $connection->credentials['instagram_account_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$instagramAccountId || !$accessToken) {
                throw new Exception('Missing instagram_account_id or access_token for webhook subscription.');
            }

            // Subscribe to Instagram webhooks
            // POST /{instagram-account-id}/subscribed_apps
            $response = Http::post("https://graph.instagram.com/v25.0/{$instagramAccountId}/subscribed_apps", [
                'subscribed_fields' => 'messages,messaging_postbacks,message_reactions',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                Log::info('Successfully subscribed to Instagram webhooks', [
                    'connection_id' => $connection->id,
                    'instagram_account_id' => $instagramAccountId,
                    'response' => $response->json(),
                ]);
            } else {
                Log::warning('Failed to subscribe to Instagram webhooks', [
                    'connection_id' => $connection->id,
                    'instagram_account_id' => $instagramAccountId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                throw new Exception('Failed to subscribe to Instagram webhooks: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
            }
        } catch (\Throwable $th) {
            Log::error('Error in webhook subscription process', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            throw $th;
        }
    }

    private function unsubscribeWebhook(Connection $connection): void
    {
        try {
            $instagramAccountId = $connection->credentials['instagram_account_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$instagramAccountId || !$accessToken) {
                Log::warning('Missing instagram_account_id or access_token for webhook unsubscription', [
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // Unsubscribe from Instagram webhooks
            // DELETE /{instagram-account-id}/subscribed_apps
            $response = Http::delete("https://graph.instagram.com/v25.0/{$instagramAccountId}/subscribed_apps", [
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                Log::info('Successfully unsubscribed from Instagram webhooks', [
                    'connection_id' => $connection->id,
                    'instagram_account_id' => $instagramAccountId,
                    'response' => $response->json(),
                ]);
            } else {
                Log::warning('Failed to unsubscribe from Instagram webhooks', [
                    'connection_id' => $connection->id,
                    'instagram_account_id' => $instagramAccountId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('Error in webhook unsubscription process', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            throw $th;
        }
    }

    private function revokeAccessToken(Connection $connection): void
    {
        try {
            $userId = $connection->credentials['user_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$userId || !$accessToken) {
                Log::warning('Missing user_id or access_token for token revocation', [
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // Revoke all permissions - this removes the app from Instagram Settings
            // DELETE /{user-id}/permissions
            $response = Http::delete("https://graph.facebook.com/v25.0/{$userId}/permissions", [
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                Log::info('Successfully revoked Instagram access token and permissions', [
                    'connection_id' => $connection->id,
                    'user_id' => $userId,
                    'response' => $response->json(),
                ]);
            } else {
                Log::warning('Failed to revoke Instagram access token', [
                    'connection_id' => $connection->id,
                    'user_id' => $userId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                // Even if revoke fails, we'll continue with disconnect
                // User can manually revoke from Instagram Settings
            }
        } catch (\Throwable $th) {
            Log::error('Error in token revocation process', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            throw $th;
        }
    }
}
