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
use App\Models\MessageReaction;
use App\Services\Flow\FlowExecutor;
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

    public function getReactionData(array $payload): ?array
    {
        $reactionMessage = $payload['msgContent']['reactionMessage'] ?? null;

        if (!$reactionMessage) {
            return null;
        }

        return [
            'emoji' => $reactionMessage['text'] ?? null,
            'message_id' => $reactionMessage['key']['id'] ?? null,
        ];
    }

    public function handle(Connection $connection, array $payload)
    {
        $event = $payload['event'] ?? null;
        $type = null;

        if(!$event) {
            Log::warning('WhatsappWApiHandler: Event type is missing in payload');
            return;
        }

        // Skip HD video dual-upload child payload. WhatsApp mengirim 2 webhook untuk video HD:
        // (1) parent message (kualitas standar) — sudah ditangani normal,
        // (2) child message HD — payload ini, hanya berisi versi HD yang ter-link ke parent via parentMessageKey.
        // Kita skip yang child agar tidak tersimpan sebagai pesan unsupported terpisah.
        $associationType = $payload['msgContent']['messageContextInfo']['messageAssociation']['associationType'] ?? null;
        if($associationType === 'HD_VIDEO_DUAL_UPLOAD') {
            Log::info('WhatsappWApiHandler: Skipping HD video dual-upload child payload', [
                'message_id' => $payload['messageId'] ?? null,
                'parent_message_id' => $payload['msgContent']['messageContextInfo']['messageAssociation']['parentMessageKey']['id'] ?? null,
            ]);
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
                        // Check if it's a reaction message
                        if (isset($payload['msgContent']['reactionMessage'])) {
                            $this->handleReaction($connection, $payload);
                        } else {
                            $this->handleReceived($connection, $payload);
                        }
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
                        // Check if it's a reaction message
                        if (isset($payload['msgContent']['reactionMessage'])) {
                            $this->handleReaction($connection, $payload);
                        } else {
                            $this->handleDelivery($connection, $payload);
                        }
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
        // WhatsApp may address the same person by a phone number and/or a @lid (Linked
        // Identity). An incoming message reveals BOTH at once: sender.id is the phone and
        // sender.senderLid (equal to chat.id here) is the @lid. We resolve a single
        // canonical contact from this pair and record the link, so a later outgoing send —
        // which knows only the @lid — still finds the same contact. See resolveContact().
        $senderId = $this->getContactUsername($payload);             // sender.id
        $contactPhone = $this->isLid($senderId) ? null : $senderId;  // numeric phone, if any
        $contactLid = $this->extractLid($payload['sender']['senderLid'] ?? null)
            ?? $this->extractLid($conversationId);
        $contactName = $this->getContactName($payload);

        if (!$conversationId || !$messageId || (!$contactPhone && !$contactLid) || !$contactName){
            Log::warning('WhatsappWApiHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_phone' => $contactPhone,
                'contact_lid' => $contactLid,
                'contact_name' => $contactName,
            ]);

            return;
        }

        // Skip webhook story
        if($conversationId === 'status'){
            Log::info('WhatsappWApiHandler: Ignoring message with conversation ID "status"');
            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactPhone, $contactLid, $contactName, &$isNewConversation, &$conversationForWelcome) {
            $contact = $this->resolveContact($connection, $contactPhone, $contactLid, $contactName, $payload);

            // Identify the open conversation by contact + connection, NOT by the external_id
            // string. WhatsApp may address the same chat by a phone number or a @lid, so the
            // reply's chat.id can differ from the external_id stored when the conversation was
            // created — e.g. a conversation started from the app (keyed by the contact's
            // phone) whose reply then arrives under a @lid. A contact has at most one open
            // conversation per connection, so this is the reliable key; matching on
            // external_id would miss it and spawn a duplicate conversation.
            $conversation = Conversation::where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending, ConversationStatus::AiHandling])
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
            } elseif ($conversation->external_id !== $conversationId) {
                // Keep the send address aligned with the address WhatsApp is actively using.
                $conversation->update(['external_id' => $conversationId]);
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

            // Only process flow for incoming messages (from user, not from bot)
            if ($message->sender_type !== SenderType::Incoming) {
                return;
            }

            $flowExecutor = new FlowExecutor();

            // Handle new conversation - start flow
            if ($isNewConversation && $conversationForWelcome) {
                if ($connection->flow_id) {
                    try {
                        $flowExecutor->startFlow($conversationForWelcome);
                    } catch (\Throwable $th) {
                        Log::error('WhatsappWApiHandler: Failed to start flow', [
                            'conversation_id' => $conversationForWelcome->id,
                            'flow_id' => $connection->flow_id,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
            } else {
                // Resume flow if there's an active flow state
                try {
                    $flowExecutor->resumeFlow($message->conversation, $this->getMessageBody($payload) ?? '');
                } catch (\Throwable $th) {
                    Log::error('WhatsappWApiHandler: Failed to resume flow', [
                        'conversation_id' => $message->conversation->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }
    }

    private function handleReaction(Connection $connection, array $payload)
    {
        if($payload['isGroup'] ?? false) {
            Log::info('WhatsappWApiHandler: Skipping group reaction');
            return;
        }

        $reactionData = $this->getReactionData($payload);

        if (!$reactionData || !$reactionData['message_id']) {
            Log::warning('WhatsappWApiHandler: Missing reaction data', [
                'reaction_data' => $reactionData,
            ]);
            return;
        }

        $emoji = $reactionData['emoji'];
        $targetMessageExternalId = $reactionData['message_id'];
        $fromMe = $payload['fromMe'] ?? false;
        $senderType = $fromMe ? SenderType::Outgoing : SenderType::Incoming;

        try {
            // Find the message that was reacted to
            $targetMessage = Message::where('external_id', $targetMessageExternalId)
                ->whereHas('conversation', function($query) use ($connection) {
                    $query->where('connection_id', $connection->id);
                })
                ->first();

            if (!$targetMessage) {
                Log::warning('WhatsappWApiHandler: Target message not found for reaction', [
                    'external_id' => $targetMessageExternalId,
                ]);
                return;
            }

            // Check if emoji is empty (unreact)
            if (empty($emoji)) {
                // Delete existing reaction
                MessageReaction::where('message_id', $targetMessage->id)
                    ->where('sender_type', $senderType)
                    ->delete();

                Log::info('WhatsappWApiHandler: Reaction removed', [
                    'message_id' => $targetMessage->id,
                    'sender_type' => $senderType->value,
                ]);
            } else {
                // Update or create reaction
                MessageReaction::updateOrCreate(
                    [
                        'message_id' => $targetMessage->id,
                        'sender_type' => $senderType,
                    ],
                    [
                        'emoji' => $emoji,
                    ]
                );

                Log::info('WhatsappWApiHandler: Reaction saved', [
                    'message_id' => $targetMessage->id,
                    'emoji' => $emoji,
                    'sender_type' => $senderType->value,
                ]);
            }

            // Broadcast message updated to refresh reactions
            broadcast(new MessageUpdated($targetMessage->fresh()));

        } catch (\Throwable $th) {
            Log::error('WhatsappWApiHandler: Failed to handle reaction', [
                'error' => $th->getMessage(),
                'target_message_id' => $targetMessageExternalId,
            ]);
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

        if (!$conversationId || !$messageId){
            Log::warning('WhatsappWApiHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
            ]);

            return;
        }

        // Skip webhook story
        if($conversationId === 'status'){
            Log::info('WhatsappWApiHandler: Ignoring message with conversation ID "status"');
            return;
        }

        // Ignore from api & already exists in database, which means this message sent via api send message endpoint, so we already have the message in database, no need to create again from delivery receipt
        if($fromMe && $fromApi && Message::where('external_id', $messageId)->exists()) {
            Log::info('WhatsappWApiHandler: Ignoring delivery receipt for message sent via API that already exists in database', [
                'message_id' => $messageId,
            ]);
            return;
        }

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $recipientId) {
            // Resolve the recipient contact first. Delivery hanya membawa chat.id — bila itu
            // sebuah @lid, resolveContact mencari contact yang sudah tertaut ke @lid tersebut
            // (via alias lid) supaya tidak dobel dengan contact bernomor telepon; kalau belum
            // ada, dibuat placeholder yang nanti diperkaya saat balasan masuk.
            $recipientPhone = $this->isLid($recipientId) ? null : $recipientId;
            $recipientLid = $this->extractLid($recipientId);
            $contact = $this->resolveContact($connection, $recipientPhone, $recipientLid, null, null);

            // Cari open conversation berdasarkan contact + connection (bukan external_id),
            // supaya pesan keluar via WhatsApp Web menempel ke conversation yang sama walau
            // WhatsApp memakai alamat (@lid vs telepon) yang berbeda dari external_id tersimpan.
            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('contact_id', $contact->id)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending, ConversationStatus::AiHandling])
                ->first();

            // Jika conversation belum ada, buat conversation baru
            if(!$conversation){
                Log::info('WhatsappWApiHandler: Creating new conversation for delivery receipt', [
                    'conversation_id' => $conversationId,
                    'connection_id' => $connection->id,
                    'recipient_id' => $recipientId,
                ]);

                // Note: Photo profile di delivery payload adalah foto sender (diri sendiri),
                // bukan foto recipient. Jadi kita skip savePhotoProfile di sini.
                // Photo akan diupdate ketika ada incoming message dari contact ini.

                $conversation = Conversation::create([
                    'contact_id'    => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id'   => $conversationId,
                    'status'        => ConversationStatus::Pending,
                ]);
            } elseif ($conversation->external_id !== $conversationId) {
                $conversation->update(['external_id' => $conversationId]);
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

        // Hanya proses untuk pesan outgoing (status update hanya relevan untuk pesan keluar)
        if($message->sender_type !== SenderType::Outgoing) {
            Log::info('WhatsappWApiHandler: Ignoring status update for non-outgoing message', [
                'message_id' => $messageId,
            ]);
            return;
        }

        $timestamp = isset($payload['moment']) ? Carbon::createFromTimestamp($payload['moment'] / 1000) : Carbon::now();

        // Update juga semua pesan outgoing sebelumnya yang status kolomnya masih kosong,
        // untuk menjaga konsistensi status (mengikuti status terbaru).
        // Note: sent_at di-cast sebagai 'timestamp' (Unix int) di model, tapi kolom DB adalah DATETIME,
        // jadi perlu dikonversi ke Carbon agar pembandingannya valid.
        $messagesToUpdate = Message::where('conversation_id', $message->conversation_id)
            ->where('sender_type', SenderType::Outgoing)
            ->where('sent_at', '<=', Carbon::createFromTimestamp($message->sent_at))
            ->whereNull($column)
            ->get();

        Log::info('WhatsappWApiHandler: Updating status for message and previous outgoing messages', [
            'message_id' => $messageId,
            'status_column' => $column,
            'timestamp' => $timestamp,
            'messages_to_update_count' => $messagesToUpdate->count(),
            'message_ids_to_update' => $messagesToUpdate->pluck('id')->toArray(),
        ]);

        foreach($messagesToUpdate as $msg) {
            $msg->update([$column => $timestamp]);
            broadcast(new MessageUpdated($msg));
        }

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

    /**
     * A WhatsApp identity is a "@lid" (Linked Identity) when it ends with @lid.
     */
    private function isLid(?string $id): bool
    {
        return $id !== null && str_ends_with($id, '@lid');
    }

    /**
     * Return the value only when it is a @lid, otherwise null.
     */
    private function extractLid(?string $id): ?string
    {
        return $this->isLid($id) ? $id : null;
    }

    /**
     * Resolve the single canonical contact for a WhatsApp identity that may be known by a
     * phone number, a @lid, or (on an incoming message) both. The contact is keyed by the
     * phone number when available and stores the @lid as an alias, so that:
     *   - an incoming message (phone + @lid) links the two,
     *   - a later outgoing send (which only carries the @lid) still resolves to the same
     *     contact instead of spawning a duplicate.
     * If a phone-keyed and a @lid-keyed contact already exist separately (the duplicate we
     * are fixing), they are merged into one.
     */
    private function resolveContact(Connection $connection, ?string $phone, ?string $lid, ?string $name, ?array $payload): Contact
    {
        $tenantId = $connection->tenant_id;

        $byPhone = $phone
            ? Contact::where('tenant_id', $tenantId)->where('external_id', $phone)->first()
            : null;

        $byLid = $lid
            ? Contact::where('tenant_id', $tenantId)
                ->where(fn($q) => $q->where('lid', $lid)->orWhere('external_id', $lid))
                ->first()
            : null;

        // Both identities already exist as separate rows → fold the @lid one into the
        // phone one so a single canonical contact remains.
        if ($byPhone && $byLid && $byPhone->id !== $byLid->id) {
            $this->mergeContact($byLid, $byPhone);
            $byLid = $byPhone;
        }

        $contact = $byPhone ?? $byLid;

        if (!$contact) {
            $contact = new Contact([
                'tenant_id'   => $tenantId,
                'external_id' => $phone ?? $lid,   // prefer the phone as the canonical id
                'channel'     => $connection->channel,
                'name'        => $name ?? $phone ?? $lid,
                'username'    => $phone,
            ]);
        }

        // Upgrade a @lid-keyed contact to its phone number once we learn it.
        if ($phone && $contact->external_id !== $phone && $this->isLid($contact->external_id)) {
            $contact->external_id = $phone;
        }
        if ($lid && $contact->lid !== $lid) $contact->lid = $lid;
        if ($phone && !$contact->username) $contact->username = $phone;
        // Enrich the display name (respecting an admin name lock and ignoring placeholders).
        if ($name && !$contact->name_locked && $contact->name !== $name && $name !== $phone && $name !== $lid) {
            $contact->name = $name;
        }

        if (!$contact->exists || $contact->isDirty()) $contact->save();

        if ($payload && ($contact->wasRecentlyCreated || !$contact->photo_profile)) {
            $this->savePhotoProfile($contact, $connection, $payload);
        }

        return $contact;
    }

    /**
     * Fold a duplicate contact into the canonical one: move its conversations across,
     * carry over a profile photo / @lid if the target lacks one, then delete the duplicate.
     */
    private function mergeContact(Contact $from, Contact $into): void
    {
        Conversation::where('contact_id', $from->id)->update(['contact_id' => $into->id]);

        if (!$into->photo_profile && $from->photo_profile) {
            $into->photo_profile = $from->photo_profile;
        }
        if (!$into->lid && $from->lid) {
            $into->lid = $from->lid;
        }
        if ($into->isDirty()) $into->save();

        $from->delete();
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
