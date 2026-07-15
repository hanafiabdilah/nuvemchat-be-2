<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
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

class WhatsappOfficialHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        // Get wa_id from contacts or messages (for different webhook types)
        return $payload['changes'][0]['value']['contacts'][0]['wa_id']
            ?? $payload['changes'][0]['value']['messages'][0]['from']
            ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['changes'][0]['value']['messages'][0]['id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        $messages = $payload['changes'][0]['value']['messages'][0] ?? [];
        $messageType = $this->getMessageType($payload);

        // Handle different message types
        return match($messageType) {
            MessageType::Text => $messages['text']['body'] ?? null,
            MessageType::Image => $messages['image']['caption'] ?? null,
            MessageType::Video => $messages['video']['caption'] ?? null,
            MessageType::Document => $messages['document']['caption'] ?? $messages['document']['filename'] ?? null,
            MessageType::Audio => null,
            // When a customer taps a reply button / list row, store the chosen
            // title as the body so agents (and the flow engine) read the choice.
            MessageType::Interactive => $messages['interactive']['button_reply']['title']
                ?? $messages['interactive']['list_reply']['title']
                ?? null,
            default => null,
        };
    }

    public function getMessageType(array $payload): MessageType
    {
        return match($payload['changes'][0]['value']['messages'][0]['type'] ?? null) {
            'text' => MessageType::Text,
            'image' => MessageType::Image,
            'video' => MessageType::Video,
            'document' => MessageType::Document,
            'audio' => MessageType::Audio,
            'voice' => MessageType::Audio,
            'interactive' => MessageType::Interactive,
            'sticker' => MessageType::Unsupported,
            'location' => MessageType::Unsupported,
            'contacts' => MessageType::Unsupported,
            default => MessageType::Unsupported,
        };
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        $timestamp = $payload['changes'][0]['value']['messages'][0]['timestamp'] ?? null;

        if ($timestamp) {
            return Carbon::createFromTimestamp($timestamp);
        }

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        return $payload['changes'][0]['value']['contacts'][0]['profile']['name'] ?? '';
    }

    public function getContactUsername(array $payload): ?string
    {
        return $payload['changes'][0]['value']['contacts'][0]['wa_id'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return $payload['changes'][0]['value']['contacts'][0]['wa_id']
            ?? $payload['changes'][0]['value']['messages'][0]['from']
            ?? null;
    }

    public function isOutgoingMessage(array $payload): bool
    {
        // WhatsApp Cloud API doesn't send echo for outgoing messages
        // This is here for interface consistency
        return false;
    }

    public function getRepliedMessageId(array $payload): ?string
    {
        return $payload['changes'][0]['value']['messages'][0]['context']['id'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $changes = $payload['changes'][0] ?? [];
        $value = $changes['value'] ?? [];

        $isReaction = ($value['messages'][0]['type'] ?? null) === 'reaction';

        // Determine event type
        $eventType = match (true) {
            $isReaction => 'reaction',
            isset($value['messages']) => 'message',
            isset($value['statuses']) => 'status',
            default => 'unsupported',
        };

        Log::info('WhatsappOfficialHandler: Processing webhook event', [
            'connection_id' => $connection->id,
            'event_type' => $eventType,
        ]);

        // Handle based on event type
        switch ($eventType) {
            case 'reaction':
                $this->handleReaction($connection, $payload);
                break;

            case 'message':
                $this->handleMessage($connection, $payload);
                break;

            case 'status':
                $this->handleStatus($connection, $payload);
                break;

            default:
                Log::warning('WhatsappOfficialHandler: Unsupported event type', [
                    'connection_id' => $connection->id,
                    'payload' => $payload,
                ]);
                break;
        }
    }

    private function handleMessage(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $contactUsername = $this->getContactUsername($payload);
        $isOutgoing = $this->isOutgoingMessage($payload);

        if (!$conversationId || !$messageId || !$contactExternalId){
            Log::warning('WhatsappOfficialHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
            ]);
            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $contactUsername, $isOutgoing, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName, $contactUsername);

            // Save profile photo for new contacts
            if($contact->wasRecentlyCreated) {
                $this->savePhotoProfile($contact, $connection, $payload);
            }

            $conversation = Conversation::where('external_id', $conversationId)
                ->where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'contact_id'    => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id'   => $conversationId,
                    'status'        => Status::Pending,
                ]);
                $isNewConversation = true;
                $conversationForWelcome = $conversation;
            }

            // Check if message already exists (prevent duplicates)
            if($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) {
                Log::info('WhatsappOfficialHandler: Duplicate message detected', [
                    'message_id' => $messageId,
                    'conversation_id' => $conversation->id,
                ]);
                return null;
            }

            // Resolve replied message via WhatsApp context.id
            $repliedMessageId = null;
            $repliedExternalId = $this->getRepliedMessageId($payload);

            if ($repliedExternalId) {
                $repliedMessage = Message::where('external_id', $repliedExternalId)
                    ->where('conversation_id', $conversation->id)
                    ->first();

                if ($repliedMessage) {
                    $repliedMessageId = $repliedMessage->id;
                } else {
                    Log::warning('WhatsappOfficialHandler: Replied message not found', [
                        'replied_external_id' => $repliedExternalId,
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => $isOutgoing ? SenderType::Outgoing : SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'replied_message_id' => $repliedMessageId,
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if($message){
            // Handle media messages
            if(in_array($messageType, [MessageType::Image, MessageType::Video, MessageType::Document, MessageType::Audio])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            Log::info('WhatsappOfficialHandler: Message processed successfully', [
                'message_id' => $message->id,
                'external_id' => $messageId,
                'conversation_id' => $message->conversation_id,
                'type' => $messageType->value,
            ]);

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
                        Log::error('WhatsappOfficialHandler: Failed to start flow', [
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
                    Log::error('WhatsappOfficialHandler: Failed to resume flow', [
                        'conversation_id' => $message->conversation->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Handle a WhatsApp reaction event. Cloud API sends reactions as a
     * message with type=reaction and a `reaction` object containing the
     * target message_id and emoji (empty emoji = unreact).
     */
    private function handleReaction(Connection $connection, array $payload)
    {
        $reaction = $payload['changes'][0]['value']['messages'][0]['reaction'] ?? [];
        $targetMessageExternalId = $reaction['message_id'] ?? null;
        $emoji = $reaction['emoji'] ?? null;

        if (!$targetMessageExternalId) {
            Log::warning('WhatsappOfficialHandler: Missing target message_id in reaction', [
                'payload' => $payload,
            ]);
            return;
        }

        // WhatsApp reactions from webhook are always from the contact (incoming)
        $senderType = SenderType::Incoming;

        try {
            $targetMessage = Message::where('external_id', $targetMessageExternalId)
                ->whereHas('conversation', function($query) use ($connection) {
                    $query->where('connection_id', $connection->id);
                })
                ->first();

            if (!$targetMessage) {
                Log::warning('WhatsappOfficialHandler: Target message not found for reaction', [
                    'external_id' => $targetMessageExternalId,
                ]);
                return;
            }

            if (empty($emoji)) {
                MessageReaction::where('message_id', $targetMessage->id)
                    ->where('sender_type', $senderType)
                    ->delete();

                Log::info('WhatsappOfficialHandler: Reaction removed', [
                    'message_id' => $targetMessage->id,
                ]);
            } else {
                MessageReaction::updateOrCreate(
                    [
                        'message_id' => $targetMessage->id,
                        'sender_type' => $senderType,
                    ],
                    [
                        'emoji' => $emoji,
                    ],
                );

                Log::info('WhatsappOfficialHandler: Reaction saved', [
                    'message_id' => $targetMessage->id,
                    'emoji' => $emoji,
                ]);
            }

            broadcast(new MessageUpdated($targetMessage->fresh()));
        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to handle reaction', [
                'error' => $th->getMessage(),
                'target_message_id' => $targetMessageExternalId,
            ]);
        }
    }

    private function handleStatus(Connection $connection, array $payload)
    {
        $statuses = $payload['changes'][0]['value']['statuses'] ?? [];

        foreach ($statuses as $status) {
            $messageId = $status['id'] ?? null;
            $statusType = $status['status'] ?? null;
            $timestamp = $status['timestamp'] ?? null;

            if (!$messageId) {
                Log::warning('WhatsappOfficialHandler: Missing message ID in status update', [
                    'status' => $status,
                ]);
                continue;
            }

            try {
                // Find the message
                $message = Message::whereHas('conversation', function($query) use ($connection) {
                    $query->where('connection_id', $connection->id);
                })
                ->where('external_id', $messageId)
                ->first();

                if (!$message) {
                    Log::warning('WhatsappOfficialHandler: Message not found for status update', [
                        'external_id' => $messageId,
                        'status' => $statusType,
                    ]);
                    continue;
                }

                $updatedAt = $timestamp ? Carbon::createFromTimestamp($timestamp) : Carbon::now();
                $wasUpdated = false;

                // Update message based on status type
                switch ($statusType) {
                    case 'sent':
                        // Message sent confirmation
                        if (!$message->delivery_at) {
                            $message->update(['delivery_at' => $updatedAt]);
                            $wasUpdated = true;
                        }
                        break;

                    case 'delivered':
                        // Message delivered to recipient's device
                        $message->update(['delivery_at' => $updatedAt]);
                        $wasUpdated = true;
                        break;

                    case 'read':
                        // Message read by recipient
                        $message->update([
                            'delivery_at' => $message->delivery_at ?? $updatedAt,
                            'read_at' => $updatedAt,
                        ]);
                        $wasUpdated = true;
                        break;

                    case 'failed':
                        // Message failed to send
                        $errors = $status['errors'] ?? [];
                        Log::error('WhatsappOfficialHandler: Message delivery failed', [
                            'message_id' => $message->id,
                            'external_id' => $messageId,
                            'errors' => $errors,
                        ]);
                        break;
                }

                if ($wasUpdated) {
                    Log::info('WhatsappOfficialHandler: Message status updated', [
                        'message_id' => $message->id,
                        'external_id' => $messageId,
                        'status' => $statusType,
                        'updated_at' => $updatedAt,
                    ]);

                    broadcast(new MessageUpdated($message));

                    // Update conversation if this is the last message
                    if($message->conversation->last_message->id == $message->id) {
                        broadcast(new ConversationUpdated($message->conversation));
                    }
                }

            } catch (\Throwable $th) {
                Log::error('WhatsappOfficialHandler: Failed to handle status update', [
                    'message_id' => $messageId,
                    'status' => $statusType,
                    'error' => $th->getMessage(),
                    'trace' => $th->getTraceAsString(),
                ]);
            }
        }
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Image => 'image',
            MessageType::Video => 'video',
            MessageType::Document => 'document',
            MessageType::Audio => null, // Will check both 'audio' and 'voice'
            default => null,
        };

        // For audio messages, check both 'audio' and 'voice' keys
        if ($messageType === MessageType::Audio) {
            $messages = $payload['changes'][0]['value']['messages'][0] ?? [];
            if (isset($messages['audio'])) {
                $mediaKey = 'audio';
            } elseif (isset($messages['voice'])) {
                $mediaKey = 'voice';
            }
        }

        if(!$mediaKey) {
            Log::warning('WhatsappOfficialHandler: Unsupported media type', [
                'message_id' => $message->id,
                'message_type' => $messageType->value,
            ]);
            return;
        }

        $mediaData = $payload['changes'][0]['value']['messages'][0][$mediaKey] ?? [];
        $mediaId = $mediaData['id'] ?? null;
        $mimeType = $mediaData['mime_type'] ?? null;

        if(!$mediaId || !$mimeType) {
            Log::warning('WhatsappOfficialHandler: Missing media ID or MIME type', [
                'message_id' => $message->id,
                'media_key' => $mediaKey,
                'media_data' => $mediaData,
            ]);
            return;
        }

        try {
            $connection = $message->conversation->connection;
            $connectionCredentials = $connection->credentials;
            $accessToken = $connectionCredentials['access_token'] ?? null;

            if(!$accessToken) {
                Log::error('WhatsappOfficialHandler: Missing access token', [
                    'message_id' => $message->id,
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // Step 1: Get media URL from WhatsApp
            $mediaUrlResponse = Http::withToken($accessToken)
                ->get("https://graph.facebook.com/v25.0/{$mediaId}");

            if(!$mediaUrlResponse->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to get media URL', [
                    'message_id' => $message->id,
                    'media_id' => $mediaId,
                    'status' => $mediaUrlResponse->status(),
                    'response' => $mediaUrlResponse->json(),
                ]);
                return;
            }

            $mediaUrl = $mediaUrlResponse->json()['url'] ?? null;

            if(!$mediaUrl) {
                Log::error('WhatsappOfficialHandler: Media URL not found in response', [
                    'message_id' => $message->id,
                    'media_id' => $mediaId,
                    'response' => $mediaUrlResponse->json(),
                ]);
                return;
            }

            // Step 2: Download media from URL
            $mediaResponse = Http::withToken($accessToken)->get($mediaUrl);

            if(!$mediaResponse->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to download media', [
                    'message_id' => $message->id,
                    'media_url' => $mediaUrl,
                    'status' => $mediaResponse->status(),
                ]);
                return;
            }

            // Determine extension from MIME type
            $extension = $this->getExtensionFromMimeType($mimeType);

            if(!$extension) {
                Log::warning('WhatsappOfficialHandler: Unknown MIME type, using bin', [
                    'message_id' => $message->id,
                    'mime_type' => $mimeType,
                ]);
                $extension = 'bin';
            }

            // Save media file
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $mediaResponse->body());

            $message->update([
                'attachment' => $mediaPath,
            ]);

            Log::info('WhatsappOfficialHandler: Media downloaded successfully', [
                'message_id' => $message->id,
                'media_id' => $mediaId,
                'media_path' => $mediaPath,
                'mime_type' => $mimeType,
                'extension' => $extension,
            ]);

        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'media_key' => $mediaKey,
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);
        }
    }

    private function savePhotoProfile(Contact $contact, Connection $connection, array $payload)
    {
        // WhatsApp Cloud API doesn't provide profile photo URL in webhook
        // Would need to make separate API call to get profile photo
        // TODO: Implement if needed
        return;
    }

    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        return match($mimeType) {
            // Images
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',

            // Videos
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'video/quicktime' => 'mov',

            // Documents
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            'text/plain' => 'txt',
            'application/json' => 'json',
            'text/csv' => 'csv',

            // Audio
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/ogg; codecs=opus' => 'ogg',
            'audio/amr' => 'amr',
            'audio/aac' => 'aac',

            default => null,
        };
    }
}
