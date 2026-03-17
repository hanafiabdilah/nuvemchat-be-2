<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'];
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['data'])) return Carbon::createFromTimestamp($payload['data']);

        return Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $telegram = new Api($connection->credentials['token']);
            $response = $telegram->sendMessage([
                'chat_id' => $conversation->external_id,
                'text' => $data['message'],
            ]);

            $responseArray = $response->toArray();

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
            Log::error('TelegramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram message');
        }
    }
}
