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
            'business_id' => ['nullable', 'string'],
            'business_name' => ['nullable', 'string'],
            'display_phone_number' => ['nullable', 'string'],
            'verified_name' => ['nullable', 'string'],
            'quality_rating' => ['nullable', 'string'],
            'pin' => ['nullable', 'string'],
            'fb_user_id' => ['nullable', 'string'],
            'token_type' => ['nullable', 'string'],
            'token_expires_at' => ['nullable', 'string'],
            'platform_type' => ['nullable', 'string'],
            'is_coexistence' => ['nullable', 'boolean'],
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
                    'business_id' => $data['business_id'] ?? null,
                    'business_name' => $data['business_name'] ?? null,
                    'display_phone_number' => $phoneInfo['display_phone_number'] ?? $data['display_phone_number'] ?? null,
                    'verified_name' => $phoneInfo['verified_name'] ?? $data['verified_name'] ?? null,
                    'quality_rating' => $phoneInfo['quality_rating'] ?? $data['quality_rating'] ?? null,
                    'pin' => $data['pin'] ?? null,
                    // App-scoped user id (ASID) from FB.login. Matches the user_id
                    // Meta sends in deauth/data-deletion signed_requests so we can
                    // reliably delete the right connection's data on request.
                    'fb_user_id' => $data['fb_user_id'] ?? null,
                    'token_type' => $data['token_type'] ?? null,
                    'token_expires_at' => $data['token_expires_at'] ?? null,
                    // Coexistence: number is also live on the WhatsApp Business
                    // App. Rewriting credentials here intentionally resets any
                    // previous smb_data_sync state — each (re-)onboarding opens
                    // a fresh 24h sync window.
                    'platform_type' => $data['platform_type'] ?? null,
                    'is_coexistence' => (bool) ($data['is_coexistence'] ?? false),
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

    /**
     * Programmatic disconnect, mirroring what "revoke access" on the
     * Facebook Business Integrations page does: unsubscribe the WABA from
     * webhooks, deregister the number from Cloud API, and revoke the app
     * permissions granted by the Facebook user. Each remote step is
     * best-effort — the token may already be revoked on Meta's side — so
     * the connection is always deactivated locally.
     */
    public function disconnect(Connection $connection): void
    {
        if (!empty($connection->credentials['access_token'])) {
            $this->unsubscribeWebhook($connection);
            $this->deregisterPhoneNumber($connection);
            $this->revokePermissions($connection);
        }

        $connection->update([
            'status' => Status::Inactive,
            'credentials' => null,
        ]);
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

    private function unsubscribeWebhook(Connection $connection): void
    {
        $businessAccountId = $connection->credentials['business_account_id'] ?? null;
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (!$businessAccountId || !$accessToken) {
            return;
        }

        // The subscription is per-WABA, not per-number: other active numbers
        // on the same WABA still need their webhooks.
        $wabaSharedWithActiveSibling = Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::WhatsappOfficial)
            ->where('status', Status::Active)
            ->where('credentials->business_account_id', $businessAccountId)
            ->exists();

        if ($wabaSharedWithActiveSibling) {
            Log::info('Skipping WABA webhook unsubscribe — WABA shared with another active connection', [
                'connection_id' => $connection->id,
                'business_account_id' => $businessAccountId,
            ]);
            return;
        }

        try {
            // DELETE /{whatsapp-business-account-id}/subscribed_apps
            $response = Http::withToken($accessToken)
                ->delete("https://graph.facebook.com/v25.0/{$businessAccountId}/subscribed_apps");

            if (!$response->successful()) {
                Log::warning('Failed to unsubscribe from WhatsApp webhooks', [
                    'connection_id' => $connection->id,
                    'business_account_id' => $businessAccountId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::warning('Error while unsubscribing from WhatsApp webhooks', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function deregisterPhoneNumber(Connection $connection): void
    {
        $phoneNumberId = $connection->credentials['phone_number_id'] ?? null;
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (!$phoneNumberId || !$accessToken) {
            return;
        }

        // Meta rejects deregistration of coexistence numbers (still live on
        // the WhatsApp Business app) — offboarding those happens on the phone.
        if (!empty($connection->credentials['is_coexistence'])) {
            return;
        }

        try {
            // POST /{phone-number-id}/deregister — removes the number from
            // Cloud API; it can be re-registered on a future connect.
            $response = Http::withToken($accessToken)
                ->post("https://graph.facebook.com/v25.0/{$phoneNumberId}/deregister");

            if (!$response->successful()) {
                Log::warning('Failed to deregister WhatsApp phone number', [
                    'connection_id' => $connection->id,
                    'phone_number_id' => $phoneNumberId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::warning('Error while deregistering WhatsApp phone number', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function revokePermissions(Connection $connection): void
    {
        $fbUserId = $connection->credentials['fb_user_id'] ?? null;
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (!$fbUserId || !$accessToken) {
            return;
        }

        // Revoking kills the token for every connection this Facebook user
        // onboarded, so keep it while an active sibling still depends on it.
        $tokenSharedWithActiveSibling = Connection::where('id', '!=', $connection->id)
            ->where('channel', Channel::WhatsappOfficial)
            ->where('status', Status::Active)
            ->where('credentials->fb_user_id', $fbUserId)
            ->exists();

        if ($tokenSharedWithActiveSibling) {
            Log::info('Skipping permission revoke — token shared with another active connection', [
                'connection_id' => $connection->id,
                'fb_user_id' => $fbUserId,
            ]);
            return;
        }

        try {
            // DELETE /{user-id}/permissions — de-authorizes the app for this
            // user (same effect as removing it from Business Integrations).
            // Also triggers our facebookDeauthorize callback.
            $response = Http::withToken($accessToken)
                ->delete("https://graph.facebook.com/v25.0/{$fbUserId}/permissions");

            if (!$response->successful()) {
                Log::warning('Failed to revoke WhatsApp app permissions', [
                    'connection_id' => $connection->id,
                    'fb_user_id' => $fbUserId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::warning('Error while revoking WhatsApp app permissions', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
