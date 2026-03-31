<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Exception;
use Illuminate\Validation\ValidationException;
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

        if(Connection::where('id', '!=', $connection->id)->where('channel', Channel::Telegram)->where('credentials->token', $data['token'])->exists()) {
            throw ValidationException::withMessages(['token' => 'The provided token is already in use for another connection.']);
        }

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

            $telegram->setWebhook([
                'url' => route('webhook.chat', $connection->id),
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

    public function checkStatus(Connection $connection): void
    {
        try {
            $telegram = new Api($connection->credentials['token']);
            $telegram->getMe();

            $connection->update([
                'status' => Status::Active,
            ]);
        } catch(TelegramResponseException $th){
            $connection->update([
                'status' => Status::Inactive,
            ]);

            throw new ConnectionException('Invalid Telegram Bot Token. Please check the credentials and try again.', 400);
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            throw new ConnectionException('An error occurred while checking the Telegram connection. Please try again later.', 500);
        }
    }
}
