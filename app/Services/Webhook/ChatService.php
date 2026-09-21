<?php

namespace App\Services\Webhook;

use App\Models\Connection;
use App\Services\Webhook\Factories\ChatFactory;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function handle(Connection $connection, array $payload)
    {
        // ⚠️ The payload is NOT logged any more. It carries the customer's
        // message body, their phone number and their name, and this line
        // copied all of it into a file that outlives the retention policy the
        // media purge enforces, that Back Office operators can read and
        // download, and that belongs to the platform rather than to the tenant
        // whose customers those people are.
        //
        // What is kept is enough to follow a delivery through the system.
        Log::debug('Chat webhook received', [
            'connection_id' => $connection->id,
            'channel' => $connection->channel->value,
            'bytes' => strlen(json_encode($payload) ?: ''),
        ]);

        $handler = ChatFactory::make($connection->channel);
        $handler->handle($connection, $payload);
    }
}
