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

/**
 * Discord bot connection. The tenant pastes a bot token from the Discord
 * Developer Portal (form-based connect, like Telegram). Outbound goes through
 * the REST API; inbound arrives over the Gateway WebSocket, consumed by the
 * long-running `php artisan discord:gateway` daemon — Discord has no webhook
 * delivery for chat messages.
 */
class DiscordChannel implements ChannelInterface
{
    public const API_BASE = 'https://discord.com/api/v10';

    public function connect(Connection $connection, array $data): void
    {
        validator($data, [
            'token' => ['required', 'string'],
        ])->validate();

        $token = trim($data['token']);

        try {
            // Validate the token and resolve the bot identity.
            $response = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                ->get(self::API_BASE . '/users/@me');

            if (!$response->successful()) {
                Log::error('Invalid Discord bot token', [
                    'connection_id' => $connection->id,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                throw ValidationException::withMessages(['token' => 'Invalid Discord bot token.']);
            }

            $bot = $response->json();
            $botUserId = (string) ($bot['id'] ?? '');

            if ($botUserId === '') {
                throw new Exception('Invalid response from Discord.');
            }

            if (Connection::where('id', '!=', $connection->id)
                ->where('channel', Channel::Discord)
                ->where('credentials->bot_user_id', $botUserId)
                ->exists()) {
                throw ValidationException::withMessages(['token' => 'This Discord bot is already connected.']);
            }

            // Application id builds the server invite URL shown to the tenant
            // (users can only DM a bot when they share a server with it).
            $application = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                ->get(self::API_BASE . '/applications/@me');
            $applicationId = $application->successful()
                ? (string) ($application->json()['id'] ?? $botUserId)
                : $botUserId;

            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'token' => $token,
                    'bot_user_id' => $botUserId,
                    'username' => $bot['username'] ?? null,
                    'name' => $bot['global_name'] ?? $bot['username'] ?? null,
                    'application_id' => $applicationId,
                ],
            ]);

            Log::info('Discord bot connected', [
                'connection_id' => $connection->id,
                'bot_user_id' => $botUserId,
                'username' => $bot['username'] ?? null,
            ]);
        } catch (ValidationException $th) {
            throw $th;
        } catch (\Throwable $th) {
            Log::error('Failed to connect Discord bot', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
            throw new Exception('An error occurred while connecting to Discord: ' . $th->getMessage());
        }
    }

    /**
     * Bot tokens can only be revoked/regenerated in the Discord Developer
     * Portal — there is no server-side revoke endpoint. Local deactivation is
     * enough: the gateway daemon reconciles against active connections and
     * drops this bot's session within a minute.
     */
    public function disconnect(Connection $connection): void
    {
        $connection->update([
            'status' => Status::Inactive,
            'credentials' => null,
        ]);
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bot ' . ($connection->credentials['token'] ?? ''),
            ])->get(self::API_BASE . '/users/@me');

            if ($response->successful()) {
                $connection->update([
                    'status' => Status::Active,
                ]);
            } else {
                $connection->update([
                    'status' => Status::Inactive,
                ]);

                throw new ConnectionException('Invalid Discord bot token. Please reconnect with a valid token.', 400);
            }
        } catch (ConnectionException $th) {
            throw $th;
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            throw new ConnectionException('An error occurred while checking the Discord connection. Please try again later.', 500);
        }
    }
}
