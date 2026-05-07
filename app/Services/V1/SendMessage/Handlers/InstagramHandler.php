<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'recipient_id' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $data['recipient_id'],
                    ],
                    'message' => [
                        'text' => $data['message'],
                    ],
                ]);

            if (!$response->successful()) {
                $responseArray = $response->json();
                
                Log::error('InstagramHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $responseArray,
                    'connection_id' => $connection->id,
                ]);

                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram message: ' . $response->body());
            }

            $responseArray = $response->json();

            Log::info('InstagramHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'recipient_id' => $data['recipient_id'],
                'message_id' => $responseArray['message_id'] ?? $responseArray['mid'] ?? null,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram message: ' . $th->getMessage());
        }
    }
}
