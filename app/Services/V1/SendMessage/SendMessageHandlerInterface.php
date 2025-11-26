<?php

namespace App\Services\V1\SendMessage;

use App\Models\Connection;

interface SendMessageHandlerInterface
{
    public function handle(Connection $connection, array $data);
}
