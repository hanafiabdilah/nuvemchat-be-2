<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Connection\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConnectionUpdated;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsappWApiHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return $payload['chat']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['messageId'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        return $payload['msgContent']['conversation']
            ?? $payload['msgContent']['audioMessage']['caption']
            ?? $payload['msgContent']['imageMessage']['caption']
            ?? $payload['msgContent']['videoMessage']['caption']
            ?? $payload['msgContent']['documentMessage']['caption']
            ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        if (isset($payload['msgContent']['conversation'])) {
            return MessageType::Text;
        } elseif (isset($payload['msgContent']['audioMessage'])) {
            return MessageType::Audio;
        } elseif (isset($payload['msgContent']['imageMessage'])) {
            return MessageType::Image;
        } elseif (isset($payload['msgContent']['videoMessage'])) {
            return MessageType::Video;
        } elseif (isset($payload['msgContent']['documentMessage'])) {
            return MessageType::Document;
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['moment'])) return Carbon::createFromTimestamp($payload['moment']);

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        return $payload['sender']['pushName'] ?? null;
    }

    public function getContactUsername(array $payload): ?string
    {
        return $payload['sender']['senderLid'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return $payload['sender']['id'] ?? null;
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

            case 'webhookReceived':
                $this->handleReceived($connection, $payload);
                break;

            default:
                throw new \Exception('Event not supported');
                break;
        }
    }

    private function handleConnected(Connection $connection, array $payload)
    {
        $credentials = $connection->credentials;
        unset($credentials['qr_code']);

        $connection->update([
            'status' => $payload['connected'] == true ? Status::Active : Status::Inactive,
            'credentials' => $credentials,
        ]);

        Log::info('Whatsapp WAPI connected', ['connection' => $connection]);

        broadcast(new ConnectionUpdated($connection));
    }

    private function handleDisconnected(Connection $connection, array $payload)
    {
        $credentials = $connection->credentials;
        unset($credentials['qr_code']);

        $connection->update([
            'status' => Status::Inactive,
            'credentials' => $credentials,
        ]);

        Log::info('Whatsapp WAPI disconnected', ['connection' => $connection]);

        broadcast(new ConnectionUpdated($connection));
    }

    private function handleReceived(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $contactUsername = $this->getContactUsername($payload);

        if (!$conversationId || !$messageId || !$contactExternalId || !$contactName){
            Log::warning('WhatsappWApiHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
                'contact_name' => $contactName,
            ]);

            return;
        }

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $contactUsername) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);

            $conversation = Conversation::firstOrCreate([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id'   => $conversationId,
            ]);

            if($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) return;

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            // if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
            //     $this->handleMediaMessage($message, $payload, $messageType);
            // }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));
        }
    }
}

?>
