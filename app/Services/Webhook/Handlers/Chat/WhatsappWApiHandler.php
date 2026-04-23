<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Connection\Status;
use App\Enums\Conversation\Status as ConversationStatus;
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
use App\Services\AutomatedMessageService;
use App\Services\Message\MessageService;
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
            ?? $payload['msgContent']['documentWithCaptionMessage']['message']['documentMessage']['caption']
            ?? $payload['msgContent']['stickerMessage']['caption']
            ?? $payload['msgContent']['reactionMessage']['text']
            ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        if (isset($payload['msgContent']['conversation']) || isset($payload['msgContent']['extendedTextMessage'])) {
            return MessageType::Text;
        } elseif (isset($payload['msgContent']['reactionMessage'])) {
            return MessageType::Reaction;
        } elseif (isset($payload['msgContent']['audioMessage'])) {
            return MessageType::Audio;
        } elseif (isset($payload['msgContent']['imageMessage'])) {
            return MessageType::Image;
        } elseif (isset($payload['msgContent']['videoMessage'])) {
            return MessageType::Video;
        } elseif (isset($payload['msgContent']['documentMessage']) || isset($payload['msgContent']['documentWithCaptionMessage'])) {
            return MessageType::Document;
        } elseif (isset($payload['msgContent']['stickerMessage'])) {
            return MessageType::Sticker;
        } elseif (isset($payload['msgContent']['locationMessage'])) {
            return MessageType::Location;
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

    public function getRepliedMessageId(array $payload): ?string
    {
        return $payload['msgContent']['extendedTextMessage']['contextInfo']['stanzaId']
            ?? $payload['msgContent']['imageMessage']['contextInfo']['stanzaId']
            ?? $payload['msgContent']['videoMessage']['contextInfo']['stanzaId']
            ?? $payload['msgContent']['audioMessage']['contextInfo']['stanzaId']
            ?? $payload['msgContent']['documentMessage']['contextInfo']['stanzaId']
            ?? $payload['msgContent']['documentWithCaptionMessage']['message']['documentMessage']['contextInfo']['stanzaId']
            ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $event = $payload['event'] ?? null;
        $type = null;

        if(!$event) {
            Log::warning('WhatsappWApiHandler: Event type is missing in payload');
            return;
        }

        if(in_array($event, ['webhookReceived', 'webhookDelivery']) && isset($payload['msgContent']['protocolMessage']['type'])){
            $type = $payload['msgContent']['protocolMessage']['type'];
        }

        switch ($event) {
            case 'webhookConnected':
                $this->handleConnected($connection, $payload);
                break;

            case 'webhookDisconnected':
                $this->handleDisconnected($connection, $payload);
                break;

            case 'webhookReceived':
                switch ($type) {
                    case 'REVOKE':
                        $this->handleDeleted($connection, $payload);
                        break;

                    case 'MESSAGE_EDIT':
                        $this->handleEdited($connection, $payload);
                        break;

                    default:
                        $this->handleReceived($connection, $payload);
                        break;
                }
                break;

            case 'webhookDelivery':
                switch ($type) {
                    case 'REVOKE':
                        $this->handleDeleted($connection, $payload);
                        break;

                    case 'MESSAGE_EDIT':
                        $this->handleEdited($connection, $payload);
                        break;

                    default:
                        $this->handleDelivery($connection, $payload);
                        break;
                }
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

    private function handleEdited(Connection $connection, array $payload)
    {
        $messageId = $payload['msgContent']['protocolMessage']['key']['id'] ?? null;
        $messageBody = $payload['msgContent']['protocolMessage']['editedMessage']['conversation']
            ?? $payload['msgContent']['protocolMessage']['editedMessage']['extendedTextMessage']['text']
            ?? $payload['msgContent']['protocolMessage']['editedMessage']['audioMessage']['caption']
            ?? $payload['msgContent']['protocolMessage']['editedMessage']['imageMessage']['caption']
            ?? $payload['msgContent']['protocolMessage']['editedMessage']['videoMessage']['caption']
            ?? $payload['msgContent']['protocolMessage']['editedMessage']['documentMessage']['caption']
            ?? null;
        $date = isset($payload['moment']) ? Carbon::createFromTimestamp($payload['moment']) : Carbon::now();

        if(!$messageId || !$messageBody) {
            Log::warning('WhatsappWApiHandler: Missing required data in payload', [
                'message_id' => $messageId,
                'message_body' => $messageBody,
            ]);

            return;
        }

        $message = Message::where('external_id', $messageId)
            ->whereHas('conversation', function($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->first();

        if(!$message){
            Log::warning('WhatsappWApiHandler: Edited message not found in database', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $message->update([
            'body' => $messageBody,
            'edited_at' => $date,
            'meta' => $payload,
        ]);

        broadcast(new MessageUpdated($message));

        if($message->conversation->last_message->id == $message->id) {
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    private function handleDeleted(Connection $connection, array $payload)
    {
        $messageId = $payload['msgContent']['protocolMessage']['key']['id'] ?? null;
        $date = isset($payload['moment']) ? Carbon::createFromTimestamp($payload['moment']) : Carbon::now();

        if(!$messageId) {
            Log::warning('WhatsappWApiHandler: Missing message ID in payload for deleted message', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $message = Message::where('external_id', $messageId)
            ->whereHas('conversation', function($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->first();

        if(!$message){
            Log::warning('WhatsappWApiHandler: Deleted message not found in database', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $message->update([
            'unsend_at' => $date,
            'meta' => $payload,
        ]);

        broadcast(new MessageUpdated($message));

        if($message->conversation->last_message->id == $message->id) {
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    private function handleReceived(Connection $connection, array $payload)
    {
        if($payload['isGroup'] ?? false) {
            Log::info('WhatsappWApiHandler: Skipping group message');
            return;
        }

        if(isset($payload['msgContent']['messageStubType']) && in_array($payload['msgContent']['messageStubType'], ['CIPHERTEXT'])) {
            Log::info('WhatsappWApiHandler: Ignoring delivery receipt for message with unsupported stub type', [
                'messageStubType' => $payload['msgContent']['messageStubType'],
            ]);
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

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $contactUsername, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);
            if($contact->wasRecentlyCreated) $this->savePhotoProfile($contact, $connection, $payload);

            $conversation = Conversation::where('external_id', $conversationId)
                ->where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending])
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'contact_id'    => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id'   => $conversationId,
                    'status'        => ConversationStatus::Pending,
                ]);
                $isNewConversation = true;
                $conversationForWelcome = $conversation;
            }

            if($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) {
                Log::info('WhatsappWApiHandler: The message already exists, ignoring duplicate', [
                    'message_id' => $messageId,
                ]);
                return;
            }

            // Lookup replied message if exists
            $repliedMessageId = null;
            $repliedMessageExternalId = $this->getRepliedMessageId($payload);

            if ($repliedMessageExternalId) {
                $repliedMessage = Message::where('external_id', $repliedMessageExternalId)
                    ->where('conversation_id', $conversation->id)
                    ->first();

                if ($repliedMessage) {
                    $repliedMessageId = $repliedMessage->id;
                } else {
                    Log::warning('WhatsappWApiHandler: Replied message not found in database', [
                        'replied_external_id' => $repliedMessageExternalId,
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'replied_message_id' => $repliedMessageId,
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document, MessageType::Sticker])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            // Send welcoming message AFTER broadcasting the main message
            if ($isNewConversation && $conversationForWelcome) {
                $automatedMessageService = new AutomatedMessageService();
                $welcomingMessage = $automatedMessageService->getWelcomingMessage($connection);

                if ($welcomingMessage) {
                    try {
                        $messageService = new MessageService();
                        $welcomeMsg = $messageService->sendMessage($conversationForWelcome, ['message' => $welcomingMessage]);

                        if ($welcomeMsg) {
                            broadcast(new MessageReceived($welcomeMsg));
                            broadcast(new ConversationUpdated($welcomeMsg->conversation));
                        }
                    } catch (\Throwable $th) {
                        Log::error('WhatsappWApiHandler: Failed to send welcoming message', [
                            'conversation_id' => $conversationForWelcome->id,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    private function handleDelivery(Connection $connection, array $payload)
    {
        $fromMe = $payload['fromMe'] ?? false; // Only from me messages have delivery receipts in W-API

        if(!$fromMe) {
            Log::info('WhatsappWApiHandler: Ignoring delivery receipt for non-outgoing or non-API message');
            return;
        }

        $fromApi = $payload['fromApi'] ?? false; // Only messages sent via API have fromApi=true
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);

        // Untuk outgoing message, contact adalah penerima (chat.id), bukan sender
        $recipientId = $conversationId; // chat.id adalah ID penerima untuk personal chat
        $recipientName = $recipientId; // Fallback ke ID jika nama tidak tersedia di delivery payload

        if (!$conversationId || !$messageId){
            Log::warning('WhatsappWApiHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ]);

            return;
        }

        // Ignore from api & already exists in database, which means this message sent via api send message endpoint, so we already have the message in database, no need to create again from delivery receipt
        if($fromMe && $fromApi && Message::where('external_id', $messageId)->exists()) {
            Log::info('WhatsappWApiHandler: Ignoring delivery receipt for message sent via API that already exists in database', [
                'message_id' => $messageId,
            ]);
            return;
        }

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $recipientId, $recipientName) {
            // Cari conversation yang masih aktif saja (bukan yang sudah resolved)
            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('external_id', $conversationId)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending])
                ->first();

            // Jika conversation belum ada, buat conversation baru beserta contact
            if(!$conversation){
                Log::info('WhatsappWApiHandler: Creating new conversation for delivery receipt', [
                    'conversation_id' => $conversationId,
                    'connection_id' => $connection->id,
                    'recipient_id' => $recipientId,
                ]);

                // Buat contact untuk penerima (recipient)
                $contact = Contact::createFromExternalData($connection, $recipientId, $recipientName, null);

                // Note: Photo profile di delivery payload adalah foto sender (diri sendiri),
                // bukan foto recipient. Jadi kita skip savePhotoProfile di sini.
                // Photo akan diupdate ketika ada incoming message dari contact ini.

                // Buat conversation baru
                $conversation = Conversation::create([
                    'contact_id'    => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id'   => $conversationId,
                    'status'        => ConversationStatus::Pending,
                ]);
            }

            // Lookup replied message if exists
            $repliedMessageId = null;
            $repliedMessageExternalId = $this->getRepliedMessageId($payload);

            if ($repliedMessageExternalId) {
                $repliedMessage = Message::where('external_id', $repliedMessageExternalId)
                    ->where('conversation_id', $conversation->id)
                    ->first();

                if ($repliedMessage) {
                    $repliedMessageId = $repliedMessage->id;
                } else {
                    Log::warning('WhatsappWApiHandler: Replied message not found in database', [
                        'replied_external_id' => $repliedMessageExternalId,
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }

            return $conversation->messages()->updateOrCreate([
                'external_id' => $messageId,
            ], [
                'sender_type' => SenderType::Outgoing,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'replied_message_id' => $repliedMessageId,
                'sent_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document, MessageType::Sticker])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    private function handleStatus(Connection $connection, array $payload)
    {
        $messageId = $this->getMessageId($payload);
        // $fromMe = $payload['fromMe'] ?? false; // Only from me messages have delivery receipts in W-API
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

        // if(!$fromMe) {
        //     Log::info('WhatsappWApiHandler: Ignoring status update for non-outgoing message');
        //     return;
        // }

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

        if($message->conversation->last_message->id == $message->id) {
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Audio => 'audioMessage',
            MessageType::Image => 'imageMessage',
            MessageType::Video => 'videoMessage',
            MessageType::Document => 'documentMessage',
            MessageType::Sticker => 'stickerMessage',
            default => null,
        };

        if(!$mediaKey) return;

        $media = $payload['msgContent'][$mediaKey] ?? null;

        if (!$media){
            if($messageType !== MessageType::Document) return;
            $media = $payload['msgContent']['documentWithCaptionMessage']['message']['documentMessage'];
            if(!$media) return;
        }

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

    private function savePhotoProfile(Contact $contact, Connection $connection, array $payload)
    {
        $profilePicture = $payload['sender']['profilePicture'] ?? null;

        if (!$profilePicture) {
            Log::info('WhatsappWApiHandler: No profile photo found for contact', [
                'contact_id' => $contact->id,
            ]);
            return;
        }

        try {
            $response = Http::timeout(30)->get($profilePicture);

            if ($response->failed()) {
                Log::error('WhatsappWApiHandler: Failed to download profile photo', [
                    'contact_id' => $contact->id,
                    'url' => $profilePicture,
                ]);
                return;
            }

            $extension = pathinfo(parse_url($profilePicture, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $photoPath = 'profile_photos/' . $contact->id . '_' . uniqid() . '.' . $extension;

            Storage::disk('local')->put($photoPath, $response->body());

            $contact->update([
                'photo_profile' => $photoPath,
            ]);

            Log::info('WhatsappWApiHandler: Profile photo saved successfully', [
                'contact_id' => $contact->id,
                'photo_profile' => $photoPath,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsappWApiHandler: Failed to save profile photo', [
                'contact_id' => $contact->id,
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
