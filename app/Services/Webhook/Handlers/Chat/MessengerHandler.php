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
use App\Models\MessageReaction;
use App\Services\Flow\FlowExecutor;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Messenger (Facebook Page) inbound events. The payload is one webhook `entry`
 * (id = page id) whose `messaging` array carries the event — the same envelope
 * Instagram uses, with two Messenger-specific twists: read/delivery receipts
 * are watermark-based (no mid), and button postbacks arrive as `postback`.
 */
class MessengerHandler implements ChatHandlerInterface
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    public function getConversationId(array $payload): ?string
    {
        // For echo messages (outgoing), use recipient's PSID as conversation
        // identifier; for incoming messages, use sender's PSID.
        $messaging = $payload['messaging'][0] ?? [];
        $isEcho = $messaging['message']['is_echo'] ?? false;

        if ($isEcho) {
            return $messaging['recipient']['id'] ?? null;
        }

        return $messaging['sender']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        $messaging = $payload['messaging'][0] ?? [];

        return $messaging['message']['mid'] ?? ($messaging['postback']['mid'] ?? null);
    }

    public function getMessageBody(array $payload): ?string
    {
        $messaging = $payload['messaging'][0] ?? [];

        // Button postback: store the button title as the message text.
        if (isset($messaging['postback'])) {
            return $messaging['postback']['title'] ?? $messaging['postback']['payload'] ?? null;
        }

        $message = $messaging['message'] ?? [];

        if (isset($message['text'])) {
            return $message['text'];
        }

        if (isset($message['attachments'][0])) {
            $attachment = $message['attachments'][0];

            // Link shares arrive as `fallback` with the URL in the payload.
            if (($attachment['type'] ?? null) === 'fallback') {
                $title = $attachment['title'] ?? null;
                $url = $attachment['payload']['url'] ?? ($attachment['url'] ?? null);

                return trim(($title ? $title . "\n" : '') . ($url ?? '')) ?: null;
            }

            if (($attachment['type'] ?? null) === 'location') {
                $coordinates = $attachment['payload']['coordinates'] ?? [];
                $lat = $coordinates['lat'] ?? null;
                $long = $coordinates['long'] ?? null;

                return ($lat !== null && $long !== null) ? "{$lat},{$long}" : null;
            }
        }

        return null;
    }

    public function getMessageType(array $payload): MessageType
    {
        $messaging = $payload['messaging'][0] ?? [];

        if (isset($messaging['postback'])) {
            return MessageType::Text;
        }

        $message = $messaging['message'] ?? [];

        if (isset($message['text'])) {
            return MessageType::Text;
        }

        if (isset($message['attachments'][0]['type'])) {
            return match ($message['attachments'][0]['type']) {
                'image' => MessageType::Image,
                'video' => MessageType::Video,
                'audio' => MessageType::Audio,
                'file' => MessageType::Document,
                'location' => MessageType::Location,
                'fallback' => MessageType::Text,
                default => MessageType::Unsupported,
            };
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        $timestamp = $payload['messaging'][0]['timestamp'] ?? null;

        if ($timestamp) {
            return Carbon::createFromTimestampMs($timestamp);
        }

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        // Messenger doesn't include the name in the webhook; the PSID is a
        // placeholder until updateContactInfo fetches the real profile.
        return $this->getContactExternalId($payload);
    }

    public function getContactUsername(array $payload): ?string
    {
        return null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        $messaging = $payload['messaging'][0] ?? [];
        $isEcho = $messaging['message']['is_echo'] ?? false;

        if ($isEcho) {
            return $messaging['recipient']['id'] ?? null;
        }

        return $messaging['sender']['id'] ?? null;
    }

    public function isOutgoingMessage(array $payload): bool
    {
        $messaging = $payload['messaging'][0] ?? [];

        return $messaging['message']['is_echo'] ?? false;
    }

    public function getRepliedMessageId(array $payload): ?string
    {
        return $payload['messaging'][0]['message']['reply_to']['mid'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];

        $eventType = match (true) {
            isset($messaging['reaction']) => 'reaction',
            isset($messaging['message']) => 'message',
            isset($messaging['postback']) => 'message',
            isset($messaging['read']) => 'read',
            isset($messaging['delivery']) => 'delivery',
            default => 'unsupported',
        };

        switch ($eventType) {
            case 'reaction':
                $this->handleReaction($connection, $payload);
                break;

            case 'message':
                $this->handleReceived($connection, $payload);
                break;

            case 'read':
                $this->handleRead($connection, $payload);
                break;

            case 'delivery':
                $this->handleDelivery($connection, $payload);
                break;

            default:
                Log::warning('MessengerHandler: Unsupported event type', [
                    'payload' => $payload,
                ]);
                break;
        }
    }

    private function handleReceived(Connection $connection, array $payload)
    {
        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);
        $contactExternalId = $this->getContactExternalId($payload);
        $contactName = $this->getContactName($payload);
        $isOutgoing = $this->isOutgoingMessage($payload);

        if (!$conversationId || !$messageId || !$contactExternalId) {
            Log::warning('MessengerHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
            ]);
            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function () use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $isOutgoing, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName);

            if ($contact->wasRecentlyCreated) {
                $this->updateContactInfo($contact, $connection, $contactExternalId);
            }

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

            // Echoes of messages the dashboard itself sent come back with the
            // same mid the send handler already stored — skip those.
            if ($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) return;

            $repliedMessageId = null;
            $repliedMessageExternalId = $this->getRepliedMessageId($payload);

            if ($repliedMessageExternalId) {
                $repliedMessage = Message::where('external_id', $repliedMessageExternalId)
                    ->where('conversation_id', $conversation->id)
                    ->first();

                if ($repliedMessage) {
                    $repliedMessageId = $repliedMessage->id;
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

        if ($message) {
            if (in_array($messageType, [MessageType::Image, MessageType::Video, MessageType::Document, MessageType::Audio])) {
                $this->handleMediaMessage($message, $payload, $messageType);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            // Only process flow for incoming messages (from user, not echoes)
            if ($message->sender_type !== SenderType::Incoming) {
                return;
            }

            $flowExecutor = new FlowExecutor();

            if ($isNewConversation && $conversationForWelcome) {
                if ($connection->flow_id) {
                    try {
                        $flowExecutor->startFlow($conversationForWelcome);
                    } catch (\Throwable $th) {
                        Log::error('MessengerHandler: Failed to start flow', [
                            'conversation_id' => $conversationForWelcome->id,
                            'flow_id' => $connection->flow_id,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
            } else {
                try {
                    $flowExecutor->resumeFlow($message->conversation, $this->getMessageBody($payload) ?? '');
                } catch (\Throwable $th) {
                    Log::error('MessengerHandler: Failed to resume flow', [
                        'conversation_id' => $message->conversation->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }
    }

    private function handleReaction(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $reaction = $messaging['reaction'] ?? [];

        $targetMessageExternalId = $reaction['mid'] ?? null;
        $action = $reaction['action'] ?? null; // 'react' or 'unreact'
        $emoji = $reaction['emoji'] ?? null;
        // Reactions from the page itself echo with the page id as sender.
        $isEcho = ($messaging['sender']['id'] ?? null) === ($payload['id'] ?? null);

        if (!$targetMessageExternalId || !$action) {
            Log::warning('MessengerHandler: Missing reaction data', [
                'reaction' => $reaction,
            ]);
            return;
        }

        $senderType = $isEcho ? SenderType::Outgoing : SenderType::Incoming;

        try {
            $targetMessage = Message::where('external_id', $targetMessageExternalId)
                ->whereHas('conversation', function ($query) use ($connection) {
                    $query->where('connection_id', $connection->id);
                })
                ->first();

            if (!$targetMessage) {
                Log::warning('MessengerHandler: Target message not found for reaction', [
                    'external_id' => $targetMessageExternalId,
                ]);
                return;
            }

            if ($action === 'unreact') {
                MessageReaction::where('message_id', $targetMessage->id)
                    ->where('sender_type', $senderType)
                    ->delete();
            } else {
                MessageReaction::updateOrCreate(
                    [
                        'message_id' => $targetMessage->id,
                        'sender_type' => $senderType,
                    ],
                    [
                        'emoji' => $emoji,
                    ]
                );
            }

            broadcast(new MessageUpdated($targetMessage->fresh()->load('reactions.contact')));
        } catch (\Throwable $th) {
            Log::error('MessengerHandler: Failed to handle reaction', [
                'error' => $th->getMessage(),
                'target_message_id' => $targetMessageExternalId,
            ]);
        }
    }

    /**
     * Messenger read receipts carry a watermark (unix ms): every outgoing
     * message sent at or before it has been read — there is no per-message mid.
     */
    private function handleRead(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $watermark = $messaging['read']['watermark'] ?? null;
        $senderId = $messaging['sender']['id'] ?? null;

        if (!$watermark || !$senderId) {
            Log::warning('MessengerHandler: Missing watermark or sender in read payload', [
                'payload' => $payload,
            ]);
            return;
        }

        $this->applyWatermark($connection, $senderId, (int) $watermark, 'read_at');
    }

    private function handleDelivery(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $watermark = $messaging['delivery']['watermark'] ?? null;
        $senderId = $messaging['sender']['id'] ?? null;

        if (!$watermark || !$senderId) {
            return;
        }

        $this->applyWatermark($connection, $senderId, (int) $watermark, 'delivery_at');
    }

    private function applyWatermark(Connection $connection, string $psid, int $watermarkMs, string $column)
    {
        try {
            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('external_id', $psid)
                ->orderByDesc('id')
                ->first();

            if (!$conversation) {
                return;
            }

            $watermark = Carbon::createFromTimestampMs($watermarkMs);

            $messages = $conversation->messages()
                ->where('sender_type', SenderType::Outgoing)
                ->whereNull($column)
                ->where('sent_at', '<=', $watermark->timestamp)
                ->get();

            foreach ($messages as $message) {
                $message->update([$column => $watermark]);
                broadcast(new MessageUpdated($message));
            }

            if ($messages->isNotEmpty()) {
                $lastMessage = $conversation->last_message;
                if ($lastMessage && $messages->contains('id', $lastMessage->id)) {
                    broadcast(new ConversationUpdated($conversation));
                }
            }
        } catch (\Throwable $th) {
            Log::error('MessengerHandler: Failed to apply watermark', [
                'column' => $column,
                'psid' => $psid,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function handleMediaMessage(Message $message, array $payload, MessageType $messageType)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $attachments = $messaging['message']['attachments'] ?? [];

        if (empty($attachments)) {
            return;
        }

        $mediaUrl = $attachments[0]['payload']['url'] ?? null;

        if (!$mediaUrl) {
            Log::warning('MessengerHandler: No media URL found in attachment', [
                'message_id' => $message->id,
                'attachment' => $attachments[0],
            ]);
            return;
        }

        try {
            // Messenger CDN URLs are pre-signed — a plain GET works (an auth
            // header would be ignored and can break some CDN edges).
            $response = Http::get($mediaUrl);

            if (!$response->successful()) {
                Log::error('MessengerHandler: Failed to download media', [
                    'message_id' => $message->id,
                    'url' => $mediaUrl,
                    'status' => $response->status(),
                ]);
                return;
            }

            $mimeType = $response->header('Content-Type');
            $extension = $this->getExtensionFromMimeType($mimeType);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $response->body());

            $message->update([
                'attachment' => $mediaPath,
            ]);
        } catch (\Throwable $th) {
            Log::error('MessengerHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function updateContactInfo(Contact $contact, Connection $connection, string $psid)
    {
        try {
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$accessToken) {
                Log::warning('MessengerHandler: Missing access token for updating contact info', [
                    'contact_id' => $contact->id,
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // PSIDs only expose the basic profile fields to the page token.
            $response = Http::get(self::GRAPH_BASE . "/{$psid}", [
                'fields' => 'first_name,last_name,name',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                $userInfo = $response->json();

                $name = $userInfo['name']
                    ?? trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''));

                $contact->update([
                    'name' => $name ?: $psid,
                ]);
            } else {
                Log::warning('MessengerHandler: Failed to fetch user info from Facebook', [
                    'contact_id' => $contact->id,
                    'connection_id' => $connection->id,
                    'psid' => $psid,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('MessengerHandler: Error updating contact info', [
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'psid' => $psid,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function getExtensionFromMimeType(?string $mimeType, string $default = 'bin'): string
    {
        $cleanMimeType = explode(';', (string) $mimeType)[0];

        return match ($cleanMimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip', 'application/x-zip-compressed' => 'zip',
            default => $default,
        };
    }
}
