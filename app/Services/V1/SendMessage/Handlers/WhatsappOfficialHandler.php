<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappOfficialHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'to' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.facebook.com/v22.0/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $data['to'],
                    'type' => 'text',
                    'text' => [
                        'body' => $data['message'],
                    ],
                ]);

            if(!$response->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'connection_id' => $connection->id,
                ]);

                throw new Exception('Failed to send WhatsApp message: ' . $response->body());
            }

            $responseArray = $response->json();

            Log::info('WhatsappOfficialHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'to' => $data['to'],
                'message_id' => $responseArray['messages'][0]['id'] ?? null,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message: ' . $th->getMessage());
        }
    }
}
