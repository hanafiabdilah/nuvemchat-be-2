<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappWApiHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'phone' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $payload = [
                'phone' => $data['phone'],
                'message' => $data['message'],
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post('https://api.w-api.app/v1/message/send-text?instanceId=' . $connection->credentials['instance_id'], $payload);

            if (!$response->successful()) {
                Log::error('WhatsappWApiHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'connection_id' => $connection->id,
                ]);

                throw new Exception('Failed to send WhatsApp message: ' . $response->body());
            }

            $responseArray = $response->json();

            Log::info('WhatsappWApiHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'phone' => $data['phone'],
                'message_id' => $responseArray['messageId'] ?? null,
                'response' => $responseArray,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message: ' . $th->getMessage());
        }
    }
}
