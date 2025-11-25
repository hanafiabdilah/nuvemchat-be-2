<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Exception;
use InvalidArgumentException;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramChannel implements ChannelInterface
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function connect(Connection $connection, array $data): void
    {
        validator($data, [
            'token' => ['required', 'string'],
        ])->validate();

        try {
            $telegram = new Api($data['token']);
            $response = $telegram->getMe();

            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'id' => $response->getId(),
                    'username' => $response->getUsername(),
                    'token' => $data['token'],
                ],
            ]);
        } catch(TelegramResponseException $th){
            throw new Exception('Invalid Telegram Bot Token provided.');
        } catch (\Throwable $th) {
            throw new Exception('An error occurred while connecting to Telegram.');
        }
    }

    public function disconnect(): void
    {
        //
    }
}
