<?php

namespace App\Services\V1\SendMessage;

use App\Models\Connection;

interface SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array;
}
