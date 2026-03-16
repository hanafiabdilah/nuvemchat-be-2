<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Connection\Status;
use App\Enums\Message\MessageType;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;

class WhatsappWApiHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return null;
    }

    public function getMessageId(array $payload): ?string
    {
        return null;
    }

    public function getMessageBody(array $payload): ?string
    {
        return null;
    }

    public function getMessageType(array $payload): MessageType
    {
        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        return null;
    }

    public function getContactUsername(array $payload): ?string
    {
        return null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $event = $payload['event'] ?? null;

        switch ($event) {
            case 'webhookConnected':
                $this->handleConnected($connection, $payload);
                break;

            case 'webhookDisconnected':
                $this->handleDisconnected($connection, $payload);
                break;

            default:
                throw new \Exception('Event not supported');
                break;
        }
    }

    private function handleConnected(Connection $connection, array $payload)
    {
        $connection->update([
            'status' => $payload['connected'] == true ? Status::Active : Status::Inactive,
        ]);

        broadcast(new ConnectionUpdated($connection->fresh()));
    }

    private function handleDisconnected(Connection $connection, array $payload)
    {
        $connection->update([
            'status' => Status::Inactive,
        ]);

        broadcast(new ConnectionUpdated($connection->fresh()));
    }
}

?>
