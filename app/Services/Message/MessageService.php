<?php

namespace App\Services\Message;

use App\Models\Conversation;
use App\Models\Message;

class MessageService
{
    public function sendMessage(Conversation $conversation, array $data): ?Message
    {
        $handler = MessageFactory::make($conversation->connection->channel, $data);
        return $handler->handleSendMessage($conversation, $data);
    }
}
