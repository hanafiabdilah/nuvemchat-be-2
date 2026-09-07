<?php

namespace App\Services\V1\SendMessage;

use App\Exceptions\ChannelCapabilityException;
use App\Exceptions\UpstreamServiceException;
use App\Models\Connection;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Validation\ValidationException;

class SendMessageService
{
    /**
     * The public API's send path — same chokepoint arrangement as
     * MessageService, for the same reason: the handlers under Handlers/ build
     * their exception text out of whatever the channel answered, and this is
     * the one place that runs for every channel.
     */
    public function sendMessage(Connection $connection, array $data): array
    {
        try {
            $handler = SendMessageFactory::make($connection->channel);

            return $handler->handleSendMessage($connection, $data);
        } catch (ValidationException|ChannelCapabilityException|UpstreamServiceException $th) {
            throw $th;
        } catch (\Throwable $th) {
            throw UpstreamError::exception(
                UpstreamProvider::forChannel($connection->channel),
                $th->getMessage(),
                context: [
                    'connection_id' => $connection->id,
                    'channel' => $connection->channel->value,
                    'action' => 'v1 send a message',
                    'exception' => $th::class,
                ],
                previous: $th,
            );
        }
    }
}
