<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordHandler implements SendMessageHandlerInterface
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'recipient_id' => 'required|string', // Discord user id
            'message' => 'required|string',
        ])->validate();

        $headers = ['Authorization' => 'Bot ' . ($connection->credentials['token'] ?? '')];

        try {
            // A DM channel must exist (or be created) before sending. Discord
            // only allows this for users sharing a server with the bot.
            $channelResponse = Http::withHeaders($headers)
                ->post(self::API_BASE . '/users/@me/channels', [
                    'recipient_id' => $data['recipient_id'],
                ]);

            if (!$channelResponse->successful() || empty($channelResponse->json()['id'])) {
                throw new Exception($channelResponse->json()['message'] ?? 'Failed to open a DM channel with this user');
            }

            $channelId = $channelResponse->json()['id'];

            $response = Http::withHeaders($headers)
                ->post(self::API_BASE . "/channels/{$channelId}/messages", [
                    'content' => $data['message'],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('DiscordHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $responseArray,
                    'connection_id' => $connection->id,
                ]);

                throw new Exception($responseArray['message'] ?? 'Failed to send Discord message: ' . $response->body());
            }

            Log::info('DiscordHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'recipient_id' => $data['recipient_id'],
                'message_id' => $responseArray['id'] ?? null,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('DiscordHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Discord message: ' . $th->getMessage());
        }
    }
}
