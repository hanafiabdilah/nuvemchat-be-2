<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\V1\SendMessage\OutgoingMessage;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessengerHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        $outgoing = OutgoingMessage::fromData(Channel::Messenger, $data, [
            'recipient_id' => 'required|string',
        ]);

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.facebook.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $data['recipient_id'],
                    ],
                    'messaging_type' => 'RESPONSE',
                    'message' => $this->content($outgoing),
                ]);

            if (!$response->successful()) {
                $responseArray = $response->json();

                Log::error('MessengerHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $responseArray,
                    'connection_id' => $connection->id,
                ]);

                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Messenger message: ' . $response->body());
            }

            $responseArray = $response->json();

            Log::info('MessengerHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'recipient_id' => $data['recipient_id'],
                'type' => $outgoing->type,
                'message_id' => $responseArray['message_id'] ?? null,
            ]);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('MessengerHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Messenger message: ' . $th->getMessage(), 0, $th);
        }
    }

    /**
     * A file goes as an attachment the platform fetches from the URL. Messenger
     * has no caption and no file name on an attachment —
     * OutgoingMessage refuses a caption before it gets here.
     */
    private function content(OutgoingMessage $outgoing): array
    {
        if ($outgoing->isText()) {
            return ['text' => $outgoing->text];
        }

        return [
            'attachment' => [
                'type' => $outgoing->type === 'document' ? 'file' : $outgoing->type,
                'payload' => [
                    'url' => $outgoing->url(),
                    'is_reusable' => true,
                ],
            ],
        ];
    }
}
