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
            // Verify the access token and get account info
            $response = Http::get('https://graph.facebook.com/v21.0/me', [
                'fields' => 'id,name,username',
                'access_token' => $data['access_token'],
            ]);

            if (!$response->successful()) {
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
            $response = Http::get('https://graph.facebook.com/v21.0/me', [
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
            // Subscribe to Instagram webhooks through Facebook Graph API
            $pageId = $connection->credentials['page_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$pageId || !$accessToken) {
                throw new Exception('Missing page_id or access_token for webhook subscription.');
            }

            $response = Http::post("https://graph.facebook.com/v21.0/{$pageId}/subscribed_apps", [
                'subscribed_fields' => 'messages,messaging_postbacks,message_echoes,message_reads',
                'access_token' => $accessToken,
            ]);

            if (!$response->successful()) {
                Log::warning('Failed to subscribe to Instagram webhooks', [
                    'connection_id' => $connection->id,
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('Error subscribing to Instagram webhooks', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function unsubscribeWebhook(Connection $connection): void
    {
        try {
            $pageId = $connection->credentials['page_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$pageId || !$accessToken) {
                return;
            }

            Http::delete("https://graph.facebook.com/v21.0/{$pageId}/subscribed_apps", [
                'access_token' => $accessToken,
            ]);
        } catch (\Throwable $th) {
            Log::error('Error unsubscribing from Instagram webhooks', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
