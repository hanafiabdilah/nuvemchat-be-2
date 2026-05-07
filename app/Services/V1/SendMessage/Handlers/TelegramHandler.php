<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Models\Connection;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramOtherException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        validator($data, [
            'chat_id' => 'required|string',
            'message' => 'required|string',
        ])->validate();

        try {
            $telegram = new Api($connection->credentials['token']);

            $response = $telegram->sendMessage([
                'chat_id' => $data['chat_id'],
                'text' => $data['message'],
            ]);

            $responseArray = $response->toArray();

            Log::info('TelegramHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'chat_id' => $data['chat_id'],
                'message_id' => $responseArray['message_id'] ?? null,
                'response' => $responseArray,
            ]);

            return $responseArray;
        } catch (TelegramSDKException $e) {
            Log::error('TelegramHandler: Telegram SDK error', [
                'error' => $e->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new TelegramSDKException($e->getMessage());
        } catch (\Throwable $th) {
            Log::error('TelegramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Telegram message: ' . $th->getMessage());
        }
    }
}
