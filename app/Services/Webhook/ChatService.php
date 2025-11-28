<?php

namespace App\Services\Webhook;

use App\Models\Connection;
use App\Services\Webhook\Factories\ChatFactory;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function handle(Connection $connection, array $payload)
    {
        Log::info('Handling chat webhook for connection ID: ' . $connection->id, ['payload' => json_encode($payload)]);

        $handler = ChatFactory::make($connection->channel);
        $handler->handle($connection, $payload);
    }
}
