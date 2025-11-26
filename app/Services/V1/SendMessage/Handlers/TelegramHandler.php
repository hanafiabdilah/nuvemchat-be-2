<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Telegram\Bot\Api;

class TelegramHandler implements SendMessageHandlerInterface
{
    public function handle(Connection $connection, array $data)
    {
        validator($data, [
            'chat_id' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $telegram = new Api($connection->credentials['token']);
            $telegram->sendMessage([
                'chat_id' => $data['chat_id'],
                'text' => $data['message'],
            ]);
        } catch (\Throwable $th) {
            throw new Exception('Failed to send Telegram message');
        }
    }
}
