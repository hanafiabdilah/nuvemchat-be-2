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
                    'username' => $accountInfo['username'] ?? null,
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
        try {
            // Unsubscribe from webhooks if needed
            $this->unsubscribeWebhook($connection);
        } catch (\Throwable $th) {
            Log::warning('Failed to unsubscribe Instagram webhook, but will update status to inactive anyway', [
                'connection' => $connection,
                'error' => $th->getMessage()
            ]);
        }

        // Always update status to inactive
        $connection->update([
            'status' => Status::Inactive,
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
}
