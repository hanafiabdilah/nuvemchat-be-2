<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Telegram\Bot\Api;

class TelegramHandler implements SendMessageHandlerInterface
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

    public function handle(Connection $connection, array $data)
    {
        validator($data, [
            'chat_id' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $telegram = new Api($connection->credentials['token']);
            $response = $telegram->sendMessage([
                'chat_id' => $data['chat_id'],
                'text' => $data['message'],
            ]);

            $responseArray = $response->toArray();

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
            throw new Exception('Failed to send Telegram message');
        }
    }
}
