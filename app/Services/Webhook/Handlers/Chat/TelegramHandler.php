<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;

class TelegramHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return $payload['message']['chat']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['message']['message_id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        return $payload['message']['text'] ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        return MessageType::Text;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['message']['date'])) return Carbon::createFromTimestamp($payload['message']['date']);

        return Carbon::now();
    }

    public function handle(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);

        if (!$conversationId || !$messageId || !$messageType) return;

        $conversation = Conversation::firstOrCreate([
            'connection_id' => $connection->id,
            'external_id'   => $conversationId,
        ]);

        if(Message::where('external_id', $messageId)->exists()) return;

        $conversation->messages()->create([
            'external_id' => $messageId,
            'sender_type' => SenderType::Incoming,
            'message_type' => $messageType,
            'body' => $this->getMessageBody($payload),
            'sent_at' => $this->getMessageSentAt($payload),
            'meta' => $payload,
        ]);
    }
}
