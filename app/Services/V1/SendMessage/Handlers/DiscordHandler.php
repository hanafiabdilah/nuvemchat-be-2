<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\V1\SendMessage\OutgoingMessage;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordHandler implements SendMessageHandlerInterface
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function handleSendMessage(Connection $connection, array $data): array
    {
        $outgoing = OutgoingMessage::fromData(Channel::Discord, $data, [
            'recipient_id' => 'required|string', // Discord user id
        ]);

        // Discord takes uploaded bytes only — there is no send-by-URL — so the
        // file is fetched before anything is said to Discord: a media_url that
        // cannot be downloaded is a 422 for the caller, not a half-sent message.
        $file = $outgoing->isText() ? null : $outgoing->download();

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
            $url = self::API_BASE . "/channels/{$channelId}/messages";

            if ($file === null) {
                $response = Http::withHeaders($headers)->post($url, ['content' => $outgoing->text]);
            } else {
                // The caption rides along as `content`, on every kind of file.
                $filename = $file->getClientOriginalName() ?: $outgoing->type;
                $payloadJson = ['attachments' => [['id' => 0, 'filename' => $filename]]];

                if ($outgoing->caption() !== null) {
                    $payloadJson['content'] = $outgoing->caption();
                }

                $response = Http::withHeaders($headers)
                    ->attach('files[0]', file_get_contents($file->getRealPath()), $filename)
                    ->post($url, ['payload_json' => json_encode($payloadJson)]);
            }

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
                'type' => $outgoing->type,
                'message_id' => $responseArray['id'] ?? null,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('DiscordHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Discord message: ' . $th->getMessage(), 0, $th);
        } finally {
            // A temp file this request downloaded; nothing else will collect it.
            if ($file !== null) {
                @unlink($file->getRealPath());
            }
        }
    }
}
