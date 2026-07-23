<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappApiwayHandler implements SendMessageHandlerInterface
{
    private function base(): string
    {
        return \App\Services\Connection\Proxy\ApiwayConfig::baseUrl();
    }
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
            ])->post($this->base() . '/v1/message/send-text?instanceId=' . $connection->credentials['instance_id'], $payload);

            if (!$response->successful()) {
                Log::error('WhatsappApiwayHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'connection_id' => $connection->id,
                ]);

                throw new Exception('Failed to send WhatsApp message: ' . $response->body());
            }

            $responseArray = $response->json();

            Log::info('WhatsappApiwayHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'phone' => $data['phone'],
                'message_id' => $responseArray['data']['id'] ?? $responseArray['messageId'] ?? null,
                'response' => $responseArray,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('WhatsappApiwayHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message: ' . $th->getMessage());
        }
    }
}
