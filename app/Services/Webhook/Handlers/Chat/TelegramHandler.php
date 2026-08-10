<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Jobs\SyncContactPhoto;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AutomatedMessageService;
use App\Services\Contact\Photo\ContactPhotoSyncer;
use App\Services\Conversation\GroupConversationService;
use App\Services\Flow\FlowExecutor;
use App\Services\Message\MessageService;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramHandler implements ChatHandlerInterface
{
    public function getConversationId(array $payload): ?string
    {
        return $payload['message']['chat']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['message']['message_id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        return $payload['message']['text'] ?? $payload['message']['caption'] ?? null;
    }

    public function getMessageType(array $payload): MessageType
    {
        if (isset($payload['message']['text'])) {
            return MessageType::Text;
        } elseif (isset($payload['message']['voice'])) {
            return MessageType::Audio;
        } elseif (isset($payload['message']['photo'])) {
            return MessageType::Image;
        } elseif(isset($payload['message']['video'])) {
            return MessageType::Video;
        } elseif(isset($payload['message']['document'])) {
            return MessageType::Document;
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        if (isset($payload['message']['date'])) return Carbon::createFromTimestamp($payload['message']['date']);

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        if (isset($payload['message']['from']['first_name']) && isset($payload['message']['from']['last_name'])) {
            return $payload['message']['from']['first_name'] . ' ' . $payload['message']['from']['last_name'];
        }

        return $payload['message']['from']['first_name'] ?? '';
    }

    public function getContactUsername(array $payload): ?string
    {
        return $payload['message']['from']['username'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return $payload['message']['from']['id'] ?? null;
    }

    public function getRepliedMessageId(array $payload): ?string
    {
        return $payload['message']['reply_to_message']['message_id'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $event = null;

        if(isset($payload['message'])){
            $event = 'received';
        }elseif(isset($payload['edited_message'])){
            $event = 'edited';
        }

        switch ($event) {
            case 'received':
                $this->handleReceived($connection, $payload);
                break;
            case 'edited':
                $this->handleEdited($connection, $payload);
                break;
            default:
                // Other update types (my_chat_member when the bot is added to /
                // removed from a group, channel_post, …) must be acknowledged,
                // not thrown: a non-200 makes Telegram retry the update and
                // hold every later update behind it — messages stop arriving.
                Log::info('TelegramHandler: Ignoring unsupported update type', [
                    'connection_id' => $connection->id,
                    'update_keys' => array_keys($payload),
                ]);
                break;
        }

    }

    private function handleReceived(Connection $connection, array $payload)
    {
        $chatType = $payload['message']['chat']['type'] ?? null;

        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $this->handleGroupReceived($connection, $payload);
            return;
        }

        if ($chatType === 'channel') {
            return; // broadcast channels are not conversations
        }

        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $contactUsername = $this->getContactUsername($payload);

        if (!$conversationId || !$messageId || !$contactExternalId || !$contactName){
            Log::warning('TelegramHandler: Missing required data in payload', [
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
            SyncContactPhoto::dispatchIfStale($contact, $connection);

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
                    Log::warning('TelegramHandler: Replied message not found in database', [
                        'replied_external_id' => $repliedMessageExternalId,
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }

            return $conversation->messages()->updateOrCreate([
                'external_id' => $messageId,
            ], [
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
            if(in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
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
                        Log::error('TelegramHandler: Failed to start flow', [
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
                    Log::error('TelegramHandler: Failed to resume flow', [
                        'conversation_id' => $message->conversation->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Ingest a message posted in a Telegram group/supergroup. The conversation
     * is keyed by the chat id (not by contact) and each message records its own
     * sender — see GroupConversationService for the channel-agnostic model.
     * Automation flows never run here; agents reply manually.
     *
     * Note: the bot only sees every group message when its privacy mode is
     * disabled in BotFather (or the bot is an admin); Telegram never delivers
     * the bot's own messages back, so there is no echo to dedupe.
     */
    private function handleGroupReceived(Connection $connection, array $payload): void
    {
        if ($this->handleGroupServiceMessage($connection, $payload)) {
            return;
        }

        $chatId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $groupTitle = $payload['message']['chat']['title'] ?? null;

        // Anonymous admins and linked channels post via sender_chat; regular
        // members via from.
        $senderChat = $payload['message']['sender_chat'] ?? null;
        $senderExternalId = $senderChat['id'] ?? $this->getContactExternalId($payload);

        if (!$chatId || !$messageId || !$senderExternalId) {
            Log::warning('TelegramHandler: Missing required data in group payload', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'sender_external_id' => $senderExternalId,
            ]);

            return;
        }

        // Locked around the transaction, not inside it: concurrent updates for
        // this group must not each create their own conversation row.
        $message = GroupConversationService::lockChat($connection, (string) $chatId, fn () => DB::transaction(function () use ($connection, $payload, $chatId, $messageId, $messageType, $groupTitle, $senderChat, $senderExternalId) {
            $conversation = GroupConversationService::resolveConversation($connection, (string) $chatId, $groupTitle);

            // The group's own avatar lives on its Contact row, same as a
            // person's — getChat is what reads it (see TelegramPhotoResolver).
            SyncContactPhoto::dispatchIfStale($conversation->contact, $connection);

            if ($senderChat) {
                $senderName = $senderChat['title'] ?? ($groupTitle ?? (string) $senderExternalId);
                $sender = GroupConversationService::resolveGroupContact($connection, (string) $senderExternalId, $senderName);
            } else {
                $senderName = $this->getContactName($payload) ?: (string) $senderExternalId;
                $sender = Contact::createFromExternalData($connection, (string) $senderExternalId, $senderName, $this->getContactUsername($payload));
            }

            SyncContactPhoto::dispatchIfStale($sender, $connection);

            GroupConversationService::addParticipant($conversation, $sender);

            $repliedMessageId = null;
            $repliedMessageExternalId = $this->getRepliedMessageId($payload);

            if ($repliedMessageExternalId) {
                $repliedMessageId = Message::where('external_id', $repliedMessageExternalId)
                    ->where('conversation_id', $conversation->id)
                    ->first()?->id;
            }

            return $conversation->messages()->updateOrCreate([
                'external_id' => $messageId,
            ], [
                'contact_id' => $sender->id,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'replied_message_id' => $repliedMessageId,
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        }));

        if ($message) {
            if (in_array($messageType, [MessageType::Audio, MessageType::Image, MessageType::Video, MessageType::Document])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));
        }
    }

    /**
     * Handle group service messages that must not become chat bubbles.
     * Returns true when the payload was a service message (handled or ignored).
     */
    private function handleGroupServiceMessage(Connection $connection, array $payload): bool
    {
        $message = $payload['message'];
        $chatId = (string) ($message['chat']['id'] ?? '');

        // Group upgraded to supergroup: Telegram switches to a new chat id.
        if (!empty($message['migrate_to_chat_id'])) {
            GroupConversationService::migrateExternalId($connection, $chatId, (string) $message['migrate_to_chat_id']);
            return true;
        }

        if (!empty($message['new_chat_title'])) {
            $conversation = GroupConversationService::rename($connection, $chatId, $message['new_chat_title']);
            if ($conversation) broadcast(new ConversationUpdated($conversation->load('contact')));
            return true;
        }

        // Telegram announces a picture change but sends only the new sizes, not
        // a file the group contact can be keyed to — re-read it through getChat
        // instead of trusting the payload, which keeps one code path for both
        // this event and the TTL refresh.
        if (!empty($message['new_chat_photo'])) {
            $group = GroupConversationService::resolveGroupContact($connection, $chatId, $message['chat']['title'] ?? null);
            SyncContactPhoto::dispatchForced($group, $connection);
            return true;
        }

        if (!empty($message['delete_chat_photo'])) {
            $group = GroupConversationService::resolveGroupContact($connection, $chatId, $message['chat']['title'] ?? null);
            app(ContactPhotoSyncer::class)->clear($group);
            return true;
        }

        $serviceKeys = [
            'new_chat_members', 'left_chat_member',
            'group_chat_created', 'supergroup_chat_created', 'migrate_from_chat_id',
            'message_auto_delete_timer_changed', 'pinned_message',
            'video_chat_scheduled', 'video_chat_started', 'video_chat_ended', 'video_chat_participants_invited',
        ];

        foreach ($serviceKeys as $key) {
            if (array_key_exists($key, $message)) {
                return true;
            }
        }

        return false;
    }

    private function handleEdited(Connection $connection, array $payload)
    {
        $messageId = $payload['edited_message']['message_id'] ?? null;
        $messageBody = $payload['edited_message']['text'] ?? $payload['edited_message']['caption'] ?? null;
        $chatId = $payload['edited_message']['chat']['id'] ?? null;
        $date = Carbon::createFromTimestamp($payload['edited_message']['edit_date'] ?? time());

        if (!$messageId || !$messageBody){
            Log::warning('TelegramHandler: Missing required data in payload', [
                'message_id' => $messageId,
                'message_body' => $messageBody,
            ]);

            return;
        }

        // Telegram message_ids are only unique per chat — scope by chat id so a
        // group edit can never hit a same-numbered message from another chat.
        $message = Message::where('external_id', $messageId)
            ->whereHas('conversation', function($query) use ($connection, $chatId) {
                $query->where('connection_id', $connection->id);
                if ($chatId) $query->where('external_id', (string) $chatId);
            })
            ->first();

        if(!$message){
            Log::warning('TelegramHandler: Edited message not found in database', [
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

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $mediaKey = match($messageType) {
            MessageType::Audio => 'voice',
            MessageType::Image => 'photo',
            MessageType::Video => 'video',
            MessageType::Document => 'document',
            default => null,
        };

        $media = $payload['message'][$mediaKey];

        if(isset($media[0])) {
            $media = $payload['message'][$mediaKey][count($payload['message'][$mediaKey]) - 1];
        }

        $response = Http::get("https://api.telegram.org/bot{$message->conversation->connection->credentials['token']}/getFile", [
            'file_id' => $media['file_id'],
        ]);

        if ($response->failed()){
            if($response->status() === 400) {
                $message->update([
                    'error' => $response->json('description'),
                ]);
            }

            return;
        }

        $filePath = $response->json('result.file_path');
        $fileUrl = "https://api.telegram.org/file/bot{$message->conversation->connection->credentials['token']}/{$filePath}";
        $extension = $this->getExtensionFromFilePath($filePath);

        if(!$fileUrl || !$extension) return;

        $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;

        Storage::disk('local')->put($mediaPath, Http::get($fileUrl)->body());

        $message->update([
            'attachment' => $mediaPath,
        ]);
    }

    private function getExtensionFromFilePath(string $filePath): ?string
    {
        $parts = explode('.', $filePath);

        return end($parts) ?: null;
    }
}
