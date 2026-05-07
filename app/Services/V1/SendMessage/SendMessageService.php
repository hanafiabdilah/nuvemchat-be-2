<?php

namespace App\Services\V1\SendMessage;

use App\Models\Connection;

class SendMessageService
{
    public function sendMessage(Connection $connection, array $data): array
    {
        $handler = SendMessageFactory::make($connection->channel);
        return $handler->handleSendMessage($connection, $data);
    }
}
