<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;

class WhatsappOfficialHandler implements SendMessageHandlerInterface
{
    public function handle(Connection $connection, array $data)
    {
        validator($data, [
            'to' => 'required|string',
            'message' => 'required|string',
        ])->validate();

    }
}
