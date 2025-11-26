<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;

class WhatsappOfficialHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] ?? null;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'])) return Carbon::createFromTimestamp($payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp']);

        return Carbon::now();
    }

    public function handle(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);

        if (!$conversationId || !$messageId) return;

        $conversation = Conversation::firstOrCreate([
            'connection_id' => $connection->id,
            'external_id'   => $conversationId,
        ]);

        if(Message::where('external_id', $messageId)->exists()) return;

        $conversation->messages()->create([
            'external_id' => $messageId,
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => $this->getMessageBody($payload),
            'sent_at' => $this->getMessageSentAt($payload),
            'meta' => $payload,
        ]);
    }
}
