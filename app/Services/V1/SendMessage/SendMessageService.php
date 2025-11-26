<?php

namespace App\Services\V1\SendMessage;

use App\Models\Connection;

class SendMessageService
{
    public function send(Connection $connection, array $data)
    {
        $handler = SendMessageFactory::make($connection->channel, $data);
        $handler->handle($connection, $data);
    }
}
