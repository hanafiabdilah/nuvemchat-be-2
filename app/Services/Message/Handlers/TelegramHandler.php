<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Telegram\Bot\Api;

class TelegramHandler implements MessageHandlerInterface
{
    public function getConversationId(array $payload): string
    {
        return $payload['chat']['id'];
    }

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

            $conversation = Conversation::firstOrCreate([
                'connection_id' => $connection->id,
                'external_id'   => $this->getConversationId($responseArray),
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            throw new Exception('Failed to send Telegram message');
        }
    }
}
