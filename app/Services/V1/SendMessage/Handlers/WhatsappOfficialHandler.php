<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;

class WhatsappOfficialHandler implements SendMessageHandlerInterface
{
    public function getConversationId(array $payload): string
    {
        return $payload['contacts'][0]['wa_id'];
    }

    public function getMessageId(array $payload): string
    {
        return $payload['messages'][0]['id'];
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now(); // Assuming message is sent now
    }


    public function handle(Connection $connection, array $data)
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

            $responseArray = $response->json();

            $conversation = Conversation::firstOrCreate([
                'connection_id' => $connection->id,
                'external_id'   => $this->getConversationId($responseArray),
            ]);

            $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);
        } catch (\Throwable $th) {
            throw new Exception('Failed to send WhatsApp message');
        }
    }
}
