<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use App\Services\Webhook\ChatWebhookSecret;
use Exception;
use Illuminate\Support\Facades\Log;
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

            // Minted fresh on every connect, because connect is also what
            // re-registers the webhook — the two have to agree, and the only
            // way to be sure they do is to set both in the same breath.
            $secret = ChatWebhookSecret::generate();

            // Telegram returns this on every delivery as
            // X-Telegram-Bot-Api-Secret-Token, which is what tells us the
            // update really came from Telegram and not from anyone who can
            // count to this connection's id.
            $telegram->setWebhook([
                'url' => route('webhook.chat', ['id' => $connection->id]),
                'secret_token' => $secret,
            ]);

            // Stored only now. Telegram is already sending the header by this
            // point, so persisting after means the two can only ever be out of
            // step in the harmless direction — a secret we do not yet know,
            // which ChatWebhookSecret lets through and logs.
            $connection->update([
                'status' => Status::Active,
                'credentials' => [
                    'id' => $response->getId(),
                    'username' => $response->getUsername(),
                    'token' => $data['token'],
                    ChatWebhookSecret::CREDENTIAL_KEY => $secret,
                ],
            ]);
        } catch(TelegramResponseException $th){
            throw new Exception('Invalid Telegram Bot Token provided.');
        } catch (\Throwable $th) {
            throw new Exception('An error occurred while connecting to Telegram.');
        }
    }

    /**
     * Re-point an already connected bot at the webhook, carrying its secret.
     *
     * For connections made before secrets existed: their webhook is registered
     * at Telegram without one, so until this runs every delivery arrives
     * unauthenticated. Used by `webhooks:secure-chat`.
     *
     * An existing secret is reused rather than replaced, so running the command
     * twice is a no-op rather than a window in which Telegram still sends the
     * previous value.
     */
    public function refreshWebhook(Connection $connection): void
    {
        $token = $connection->credentials['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new ConnectionException('This Telegram connection has no bot token stored.', 422);
        }

        $secret = ChatWebhookSecret::of($connection) ?: ChatWebhookSecret::generate();

        (new Api($token))->setWebhook([
            'url' => route('webhook.chat', ['id' => $connection->id]),
            'secret_token' => $secret,
        ]);

        ChatWebhookSecret::store($connection, $secret);
    }

    public function disconnect(Connection $connection): void
    {
        try {
            $telegram = new Api($connection->credentials['token']);
            $telegram->deleteWebhook();
        } catch (TelegramResponseException $th) {
            Log::warning('Failed to delete Telegram webhook, but will update status to inactive anyway', [
                'connection' => $connection,
                'error' => $th->getMessage()
            ]);
        } catch (\Throwable $th) {
            Log::warning('An error occurred while disconnecting from Telegram, but will update status to inactive anyway', [
                'connection' => $connection,
                'error' => $th->getMessage()
            ]);
        }

        // Always update status to inactive, even if webhook deletion failed
        $connection->update([
            'status' => Status::Inactive,
        ]);
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
