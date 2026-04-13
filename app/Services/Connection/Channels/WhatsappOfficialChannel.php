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

class WhatsappOfficialChannel implements ChannelInterface
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
            'phone_number_id' => ['required', 'string'],
            'access_token' => ['required', 'string'],
            'business_account_id' => ['required', 'string'],
            'display_phone_number' => ['nullable', 'string'],
            'verified_name' => ['nullable', 'string'],
            'quality_rating' => ['nullable', 'string'],
            'token_type' => ['nullable', 'string'],
            'token_expires_at' => ['nullable', 'string'],
        ])->validate();

        // Check if this phone number is already connected
        if(Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::WhatsappOfficial)
            ->where('credentials->phone_number_id', $data['phone_number_id'])
            ->exists()) {
            throw ValidationException::withMessages(['phone_number_id' => 'This WhatsApp phone number is already connected.']);
        }

        try {
            // Verify the access token and get phone number info
            $phoneNumberId = $data['phone_number_id'];
            $accessToken = $data['access_token'];

            $response = Http::withToken($accessToken)
                ->get("https://graph.facebook.com/v25.0/{$phoneNumberId}", [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating',
                ]);

            if (!$response->successful()) {
                Log::error('Invalid WhatsApp access token or phone number ID', [
                    'response' => $response->json(),
                ]);
                throw new Exception('Invalid WhatsApp access token or phone number ID provided.');
            }

            $phoneInfo = $response->json();

            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'phone_number_id' => $data['phone_number_id'],
                    'access_token' => $data['access_token'],
                    'business_account_id' => $data['business_account_id'],
                    'display_phone_number' => $phoneInfo['display_phone_number'] ?? $data['display_phone_number'] ?? null,
                    'verified_name' => $phoneInfo['verified_name'] ?? $data['verified_name'] ?? null,
                    'quality_rating' => $phoneInfo['quality_rating'] ?? $data['quality_rating'] ?? null,
                    'token_type' => $data['token_type'] ?? null,
                    'token_expires_at' => $data['token_expires_at'] ?? null,
                ],
            ]);

            // Subscribe to webhooks
            $this->subscribeWebhook($connection);

        } catch(ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('Failed to connect WhatsApp account', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
            throw new Exception('An error occurred while connecting to WhatsApp: ' . $th->getMessage());
        }
    }

    public function disconnect(Connection $connection): void
    {
        throw new Exception('WhatsApp does not support programmatic disconnection. Please instruct the user to disconnect the account from their WhatsApp settings or revoke access from the Facebook Business Integrations page.');
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $phoneNumberId = $connection->credentials['phone_number_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$phoneNumberId || !$accessToken) {
                throw new Exception('Missing credentials for status check.');
            }

            $response = Http::withToken($accessToken)
                ->get("https://graph.facebook.com/v25.0/{$phoneNumberId}", [
                    'fields' => 'id,display_phone_number,verified_name',
                ]);

            if ($response->successful()) {
                $connection->update([
                    'status' => Status::Active,
                ]);

                Log::info('WhatsApp connection status checked - Active', [
                    'connection_id' => $connection->id,
                ]);
            } else {
                $connection->update([
                    'status' => Status::Inactive,
                ]);

                Log::warning('WhatsApp connection status checked - Inactive', [
                    'connection_id' => $connection->id,
                    'response' => $response->json(),
                ]);

                throw new ConnectionException('Invalid WhatsApp access token. Please reconnect your account.', 400);
            }
        } catch (ConnectionException $th) {
            throw $th;
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            Log::error('Failed to check WhatsApp connection status', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            throw new ConnectionException('An error occurred while checking the WhatsApp connection. Please try again later.', 500);
        }
    }

    private function subscribeWebhook(Connection $connection): void
    {
        try {
            $businessAccountId = $connection->credentials['business_account_id'] ?? null;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$businessAccountId || !$accessToken) {
                throw new Exception('Missing business_account_id or access_token for webhook subscription.');
            }

            // Subscribe to WhatsApp webhooks
            // POST /{whatsapp-business-account-id}/subscribed_apps
            $response = Http::withToken($accessToken)
                ->post("https://graph.facebook.com/v25.0/{$businessAccountId}/subscribed_apps");

            if ($response->successful()) {
                Log::info('Successfully subscribed to WhatsApp webhooks', [
                    'connection_id' => $connection->id,
                    'business_account_id' => $businessAccountId,
                    'response' => $response->json(),
                ]);
            } else {
                Log::warning('Failed to subscribe to WhatsApp webhooks', [
                    'connection_id' => $connection->id,
                    'business_account_id' => $businessAccountId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                throw new Exception('Failed to subscribe to WhatsApp webhooks: ' . ($response->json()['error']['message'] ?? 'Unknown error'));
            }
        } catch (\Throwable $th) {
            Log::error('Error in webhook subscription process', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            throw $th;
        }
    }
}
