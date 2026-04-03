<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappOfficialHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['messages'][0]['id'];
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now(); // Assuming message is sent now
    }


    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.facebook.com/v22.0/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $conversation->external_id,
                    'type' => 'text',
                    'text' => [
                        'body' => $data['message'],
                    ],
                ]);

            $responseArray = $response->json();

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message');
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Image sending not implemented for WhatsApp Official API');
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Audio sending not implemented for WhatsApp Official API');
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Video sending not implemented for WhatsApp Official API');
    }
}
