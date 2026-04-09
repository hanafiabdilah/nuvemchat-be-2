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

class InstagramHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'] ?? $payload['mid'] ?? uniqid('ig_', true);
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'text' => $data['message'],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send message', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram message');
            }

            Log::info('InstagramHandler: Message sent successfully', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
            ]);

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
            Log::error('InstagramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram message: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Image sending not implemented for Instagram API');
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Audio sending not implemented for Instagram API');
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Video sending not implemented for Instagram API');
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        throw new Exception('Document sending not implemented for Instagram API');
    }
}
