<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Connection\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConnectionUpdated;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            ?? $payload['msgContent']['extendedTextMessage']['text']
            ?? $payload['msgContent']['audioMessage']['caption']
            ?? $payload['msgContent']['imageMessage']['caption']
            ?? $payload['msgContent']['videoMessage']['caption']
            ?? $payload['msgContent']['documentMessage']['caption']
            ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        if (isset($payload['msgContent']['conversation']) || isset($payload['msgContent']['extendedTextMessage'])) {
            return MessageType::Text;
        }  elseif (isset($payload['msgContent']['audioMessage'])) {
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
        return $payload['sender']['id'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return $payload['sender']['id'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $event = $payload['event'] ?? null;

        if(!$event) return;

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

            case 'webhookDelivery':
                $this->handleDelivery($connection, $payload);
                break;

            case 'webhookStatus':
                $this->handleStatus($connection, $payload);
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
        if($payload['isGroup'] ?? false) {
            Log::info('WhatsappWApiHandler: Skipping group message');
            return;
        }

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
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    private function handleDelivery(Connection $connection, array $payload)
    {
        $fromMe = $payload['fromMe'] ?? false; // Only from me messages have delivery receipts in W-API
        $fromApi = $payload['fromApi'] ?? false; // True = send via panel, False = send via another device (like whatsapp web, whatsapp desktop or another phone)

        if(!$fromMe) {
            Log::info('WhatsappWApiHandler: Ignoring delivery receipt for non-outgoing or non-API message');
            return;
        }

        if($fromApi) {
            Log::info('WhatsappWApiHandler: Ignoring delivery receipt for message sent via API (panel)');
            return;
        }

        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);

        if (!$conversationId || !$messageId){
            Log::warning('WhatsappWApiHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ]);

            return;
        }

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType) {
            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('external_id', $conversationId)
                ->first();

            if(!$conversation){
                Log::warning('WhatsappWApiHandler: Conversation not found for delivery receipt', [
                    'conversation_id' => $conversationId,
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            return $conversation->messages()->updateOrCreate([
                'external_id' => $messageId,
            ], [
                'sender_type' => SenderType::Outgoing,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    private function handleStatus(Connection $connection, array $payload)
    {
        $messageId = $this->getMessageId($payload);
        $fromMe = $payload['fromMe'] ?? false; // Only from me messages have delivery receipts in W-API
        $column = match($payload['status'] ?? null) {
            'DELIVERY' => 'delivery_at',
            'READ' => 'read_at',
            default => null,
        };

        if (!$messageId){
            Log::warning('WhatsappWApiHandler: Missing message ID', [
                'message_id' => $messageId,
            ]);
            return;
        }

        if(!$fromMe) {
            Log::info('WhatsappWApiHandler: Ignoring status update for non-outgoing message');
            return;
        }

        if(!$column) {
            Log::info('WhatsappWApiHandler: Ignoring unsupported status update', ['status' => $payload['status'] ?? null]);
            return;
        }

        $message = Message::whereHas('conversation', function($query) use ($connection) {
            $query->where('connection_id', $connection->id);
        })->where('external_id', $messageId)->first();

        if(!$message){
            Log::warning('WhatsappWApiHandler: Message not found for status update', [
                'message_id' => $messageId,
            ]);
            return;
        }

        $message->update([
            $column => isset($payload['moment']) ? Carbon::createFromTimestamp($payload['moment'] / 1000) : Carbon::now(),
        ]);

        broadcast(new MessageUpdated($message));
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Audio => 'audioMessage',
            MessageType::Image => 'imageMessage',
            MessageType::Video => 'videoMessage',
            MessageType::Document => 'documentMessage',
            default => null,
        };

        if (!$mediaKey || !isset($payload['msgContent'][$mediaKey])) {
            return;
        }

        $media = $payload['msgContent'][$mediaKey];

        $mediaKeyValue = $media['mediaKey'] ?? null;
        $directPath = $media['directPath'] ?? null;
        $mimeType = $media['mimetype'] ?? null;

        if (!$mediaKeyValue || !$directPath || !$mimeType) {
            Log::error('WhatsappWApiHandler: Missing media data', [
                'message_id' => $message->id,
                'has_mediaKey' => isset($media['mediaKey']),
                'has_directPath' => isset($media['directPath']),
                'has_mimetype' => isset($media['mimetype']),
            ]);
            return;
        }

        try {
            // Step 1: Request decrypted file link from W-API
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $message->conversation->connection->credentials['token'],
            ])->post("https://api.w-api.app/v1/message/download-media?instanceId=" . $message->conversation->connection->credentials['instance_id'], [
                'mediaKey' => $mediaKeyValue,
                'directPath' => $directPath,
                'type' => $messageType->value,
                'mimetype' => $mimeType,
            ]);

            if ($response->failed() || $response->json('error') === true) {
                $message->update([
                    'error' => 'Failed to get media download link',
                ]);
                return;
            }

            $fileLink = $response->json('fileLink');

            if (!$fileLink) {
                $message->update([
                    'error' => 'No file link returned from API',
                ]);
                return;
            }

            // Step 2: Download the decrypted file
            $fileResponse = Http::timeout(60)->get($fileLink);

            if ($fileResponse->failed()) {
                $message->update([
                    'error' => 'Failed to download media file',
                ]);
                return;
            }

            // Step 3: Save to storage
            $extension = $this->getExtensionFromMimeType($mimeType);
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;

            Storage::disk('local')->put($mediaPath, $fileResponse->body());

            $message->update([
                'attachment' => $mediaPath,
            ]);

            Log::info('WhatsappWApiHandler: Media downloaded successfully', [
                'message_id' => $message->id,
                'media_path' => $mediaPath,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsappWApiHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $message->update([
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];

        // Handle special cases like "audio/ogg; codecs=opus"
        $cleanMimeType = explode(';', $mimeType)[0];

        return $mimeToExt[$cleanMimeType] ?? 'bin';
    }
}

?>
