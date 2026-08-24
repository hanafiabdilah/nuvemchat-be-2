<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Jobs\DownloadInboundMedia;
use App\Jobs\SyncContactPhoto;
use App\Jobs\SyncContactProfile;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\Conversation\LastAgentRouter;
use App\Services\Flow\FlowExecutor;
use App\Services\Message\MessageService;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use App\Services\Webhook\Contracts\DownloadsInboundMedia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InstagramHandler implements ChatHandlerInterface, DownloadsInboundMedia
{
    /** Message types whose bytes are fetched after the message is broadcast. */
    private const MEDIA_TYPES = [
        MessageType::Image,
        MessageType::Video,
        MessageType::Document,
        MessageType::Audio,
    ];

    /** Attachment kinds that are a shared post rather than a plain file. */
    private const SHARE_TYPES = ['share', 'ig_post', 'ig_reel'];

    /** Fallback body for a share whose caption is empty. */
    private const SHARE_FALLBACK_BODY = 'Instagram post shared';

    public function getConversationId(array $payload): ?string
    {
        // For echo messages (outgoing), use recipient's ID as conversation identifier
        // For incoming messages, use sender's ID
        $messaging = $payload['messaging'][0] ?? [];
        $isEcho = $messaging['message']['is_echo'] ?? false;

        if ($isEcho) {
            return $messaging['recipient']['id'] ?? null;
        }

        return $messaging['sender']['id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $payload['messaging'][0]['message']['mid'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        $message = $payload['messaging'][0]['message'] ?? [];

        // Text message
        if (isset($message['text'])) {
            return $message['text'];
        }

        // Check for share/post attachments
        if (isset($message['attachments'][0])) {
            $share = self::shareAttachment($message['attachments']);

            if ($share) {
                return self::shareBody($share);
            }

            // Media with caption
            if (isset($message['attachments'][0]['payload']['caption'])) {
                return $message['attachments'][0]['payload']['caption'];
            }
        }

        return null;
    }

    /**
     * The shared post as the agent should read it: the link to the post, and
     * nothing else. The caption is the poster's copy, not something the
     * customer wrote — repeating it in the bubble buries the one line the
     * agent is going to click. It stays in `meta.instagram_share` either way.
     *
     * Only reels come with a link: there `payload.url` already *is* the public
     * permalink. A shared feed post carries a signed lookaside CDN url and an
     * `ig_post_media_id` instead, and neither leads back to the post — the id
     * is refused by the Graph API when the media is not the connected
     * account's, and it lives in a different number space than the shortcode
     * in an instagram.com/p/ url, so it cannot be converted either. For those
     * the caption is all there is, so it becomes the body rather than leaving
     * a bubble with nothing in it.
     */
    private static function shareBody(array $attachment): string
    {
        $permalink = self::sharePermalink($attachment);

        if ($permalink !== null) {
            return $permalink;
        }

        $caption = trim((string) ($attachment['payload']['title'] ?? ''));

        return $caption !== '' ? $caption : self::SHARE_FALLBACK_BODY;
    }

    /**
     * What the bubble draws from: which kind of post it was, where it lives,
     * and the caption — kept whether or not the body shows it, since it is the
     * only description of a post we no longer mirror.
     */
    private static function shareData(array $attachment): array
    {
        $payload = $attachment['payload'] ?? [];
        $caption = trim((string) ($payload['title'] ?? ''));

        return [
            'kind' => ($attachment['type'] ?? null) === 'ig_reel' ? 'reel' : 'post',
            'permalink' => self::sharePermalink($attachment),
            'media_id' => $payload['reel_video_id'] ?? $payload['ig_post_media_id'] ?? null,
            'caption' => $caption !== '' ? $caption : null,
        ];
    }

    /**
     * The raw webhook, plus the share pulled out of it. The bubble reads the
     * curated copy — MessageResource never lets an Instagram payload through
     * to the SPA — and keeping it on the row means a share stays readable even
     * after Instagram changes the shape of the attachment it came from.
     */
    private static function metaFor(array $payload): array
    {
        $attachments = $payload['messaging'][0]['message']['attachments'] ?? [];
        $share = is_array($attachments) && $attachments ? self::shareAttachment($attachments) : null;

        return $share ? $payload + ['instagram_share' => self::shareData($share)] : $payload;
    }

    /**
     * The first share-shaped attachment, preferring one that carries a
     * permalink: a single webhook can repeat the same post as both `share` and
     * `ig_post`, and a mixed batch should still surface the linkable one.
     */
    private static function shareAttachment(array $attachments): ?array
    {
        $shares = array_values(array_filter(
            $attachments,
            fn ($attachment) => in_array($attachment['type'] ?? null, self::SHARE_TYPES),
        ));

        foreach ($shares as $share) {
            if (self::sharePermalink($share) !== null) {
                return $share;
            }
        }

        return $shares[0] ?? null;
    }

    /** `payload.url` when it points at instagram.com, not at the CDN mirror. */
    private static function sharePermalink(array $attachment): ?string
    {
        $url = $attachment['payload']['url'] ?? null;

        if (!is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return null;
        }

        $host = strtolower($host);

        return ($host === 'instagram.com' || str_ends_with($host, '.instagram.com'))
            ? $url
            : null;
    }

    public function getMessageType(array $payload): MessageType
    {
        $message = $payload['messaging'][0]['message'] ?? [];

        // Check if it's a text message
        if (isset($message['text'])) {
            return MessageType::Text;
        }

        // A shared post gets its own bubble — picked the same way
        // getMessageBody() picks it, so type and body never disagree about
        // which attachment the message is showing.
        if (isset($message['attachments'][0]) && self::shareAttachment($message['attachments'])) {
            return MessageType::InstagramShare;
        }

        // Check attachments
        if (isset($message['attachments'][0]['type'])) {
            return match($message['attachments'][0]['type']) {
                'image' => MessageType::Image,
                'video' => MessageType::Video,
                'audio' => MessageType::Audio,
                'file' => MessageType::Document,
                'ephemeral' => MessageType::Unsupported,
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

    /**
     * Instagram never carries a name in the webhook, so the contact's own
     * scoped id stands in until SyncContactProfile reads the real one.
     *
     * It has to be the *contact's* id, not the sender's: on an echo the sender
     * is the business account, and using it labelled every outbound-first
     * thread with the account's own numeric id.
     */
    public function getContactName(array $payload): ?string
    {
        return $this->getContactExternalId($payload);
    }

    public function getContactUsername(array $payload): ?string
    {
        // Instagram doesn't provide username in webhook
        return null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        // For echo messages (outgoing), contact is the recipient
        // For incoming messages, contact is the sender
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

        // Determine event type
        $eventType = match (true) {
            isset($messaging['reaction']) => 'reaction',
            isset($messaging['message_edit']) => 'message_edit',
            isset($messaging['message']['is_deleted']) => 'message_delete',
            isset($messaging['message']) => 'message',
            isset($messaging['read']) => 'read',
            default => 'unsupported',
        };

        // Handle based on event type
        switch ($eventType) {
            case 'reaction':
                $this->handleReaction($connection, $payload);
                break;

            case 'message_edit':
                $this->handleMessageEdit($connection, $payload);
                break;

            case 'message_delete':
                $this->handleMessageDelete($connection, $payload);
                break;

            case 'message':
                $this->handleReceived($connection, $payload);
                break;

            case 'read':
                $this->handleStatus($connection, $payload);
                break;

            default:
                Log::warning('InstagramHandler: Unsupported event type', [
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
        $contactUsername = $this->getContactUsername($payload);
        $isOutgoing = $this->isOutgoingMessage($payload);

        if (!$conversationId || !$messageId || !$contactExternalId){
            Log::warning('InstagramHandler: Missing required data in payload', [
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

            // Resolve the scoped id into a real name. Not gated on
            // wasRecentlyCreated: when the business writes first, the lookup at
            // creation time is refused (the person is not in a conversation
            // with the account yet) and this reply is the first moment it can
            // succeed. SyncContactProfile stops asking once a username sticks.
            SyncContactProfile::dispatchIfUnresolved($contact, $connection);

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

            if($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) return;

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
                    Log::warning('InstagramHandler: Replied message not found in database', [
                        'replied_external_id' => $repliedMessageExternalId,
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
                'meta' => self::metaFor($payload),
            ]);
        });

        if($message){
            // The download is a CDN round-trip; it runs off the queue so the
            // bubble (and its caption) reaches the dashboard first.
            if(in_array($messageType, self::MEDIA_TYPES)) {
                DownloadInboundMedia::dispatchFor($message);
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
                // A contact who came straight back reaches the agent who was
                // already helping them; the bot is for strangers.
                $returnedToAgent = LastAgentRouter::route($conversationForWelcome);

                if (! $returnedToAgent && $connection->flow_id) {
                    try {
                        $flowExecutor->startFlow($conversationForWelcome);
                    } catch (\Throwable $th) {
                        Log::error('InstagramHandler: Failed to start flow', [
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
                    Log::error('InstagramHandler: Failed to resume flow', [
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
        $isEcho = $messaging['sender']['id'] === $payload['id']; // Check if it's from the page (outgoing)

        if (!$targetMessageExternalId || !$action) {
            Log::warning('InstagramHandler: Missing reaction data', [
                'reaction' => $reaction,
            ]);
            return;
        }

        $senderType = $isEcho ? SenderType::Outgoing : SenderType::Incoming;

        try {
            // Find the message that was reacted to
            $targetMessage = Message::where('external_id', $targetMessageExternalId)
                ->whereHas('conversation', function($query) use ($connection) {
                    $query->where('connection_id', $connection->id);
                })
                ->first();

            if (!$targetMessage) {
                Log::warning('InstagramHandler: Target message not found for reaction', [
                    'external_id' => $targetMessageExternalId,
                ]);
                return;
            }

            if ($action === 'unreact') {
                // Delete existing reaction
                MessageReaction::where('message_id', $targetMessage->id)
                    ->where('sender_type', $senderType)
                    ->delete();

                Log::info('InstagramHandler: Reaction removed', [
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

                Log::info('InstagramHandler: Reaction saved', [
                    'message_id' => $targetMessage->id,
                    'emoji' => $emoji,
                    'sender_type' => $senderType->value,
                ]);
            }

            // Broadcast message updated to refresh reactions
            broadcast(new MessageUpdated($targetMessage->fresh()->load('reactions.contact')));

        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle reaction', [
                'error' => $th->getMessage(),
                'target_message_id' => $targetMessageExternalId,
            ]);
        }
    }

    private function handleMessageEdit(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $messageEdit = $messaging['message_edit'] ?? [];
        $messageId = $messageEdit['mid'] ?? null;
        $newText = $messageEdit['text'] ?? null;
        $numEdit = $messageEdit['num_edit'] ?? 0;
        $timestamp = $messaging['timestamp'] ?? null;
        $numEdit = $messaging['message_edit']['num_edit'] ?? null;

        if($numEdit === 0) {
            Log::warning('InstagramHandler: Edit event received with num_edit = 0, ignoring', [
                'payload' => $payload,
            ]);
            return;
        }

        if (!$messageId) {
            Log::warning('InstagramHandler: Missing message ID in edit payload', [
                'payload' => $payload,
            ]);
            return;
        }

        try {
            // Find the message
            $message = Message::whereHas('conversation', function($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->where('external_id', $messageId)
            ->first();

            if ($message) {
                $editedAt = $timestamp ? Carbon::createFromTimestampMs($timestamp) : Carbon::now();

                // Update message with new text and edited_at timestamp
                $message->update([
                    'body' => $newText,
                    'edited_at' => $editedAt,
                    'meta' => array_merge($message->meta ?? [], [
                        'num_edit' => $numEdit,
                        'last_edit_payload' => $payload,
                    ]),
                ]);

                Log::info('InstagramHandler: Message edited successfully', [
                    'message_id' => $message->id,
                    'external_id' => $messageId,
                    'num_edit' => $numEdit,
                    'edited_at' => $message->edited_at,
                ]);

                // Broadcast the message update
                broadcast(new MessageUpdated($message));

                // Update conversation if this is the last message
                if($message->conversation->last_message->id == $message->id) {
                    broadcast(new ConversationUpdated($message->conversation));
                }
            } else {
                Log::warning('InstagramHandler: Message not found for edit event', [
                    'external_id' => $messageId,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle message edit', [
                'message_id' => $messageId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function handleMessageDelete(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $message = $messaging['message'] ?? [];
        $messageId = $message['mid'] ?? null;
        $timestamp = $messaging['timestamp'] ?? null;

        if (!$messageId || !($message['is_deleted'] ?? false)) {
            Log::warning('InstagramHandler: Invalid delete message payload', [
                'payload' => $payload,
            ]);
            return;
        }

        try {
            // Find the message
            $messageModel = Message::whereHas('conversation', function($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->where('external_id', $messageId)
            ->first();

            if ($messageModel) {
                $unsendAt = $timestamp ? Carbon::createFromTimestampMs($timestamp) : Carbon::now();

                // Update message with unsend_at timestamp
                $messageModel->update([
                    'unsend_at' => $unsendAt,
                    'meta' => array_merge($messageModel->meta ?? [], [
                        'delete_payload' => $payload,
                    ]),
                ]);

                Log::info('InstagramHandler: Message deleted successfully', [
                    'message_id' => $messageModel->id,
                    'external_id' => $messageId,
                    'unsend_at' => $messageModel->unsend_at,
                ]);

                // Broadcast the message update
                broadcast(new MessageUpdated($messageModel));

                // Update conversation if this is the last message
                if($messageModel->conversation->last_message->id == $messageModel->id) {
                    broadcast(new ConversationUpdated($messageModel->conversation));
                }
            } else {
                Log::warning('InstagramHandler: Message not found for delete event', [
                    'external_id' => $messageId,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle message delete', [
                'message_id' => $messageId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function handleStatus(Connection $connection, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $read = $messaging['read'] ?? [];
        $messageId = $read['mid'] ?? null;
        $timestamp = $messaging['timestamp'] ?? null;

        if (!$messageId) {
            Log::warning('InstagramHandler: Missing message ID in read status payload', [
                'payload' => $payload,
            ]);
            return;
        }

        try {
            // Find the message and update read status
            $message = Message::whereHas('conversation', function($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->where('external_id', $messageId)
            ->first();

            if ($message) {
                $readAt = $timestamp ? Carbon::createFromTimestampMs($timestamp) : Carbon::now();

                $message->update([
                    'read_at' => $readAt,
                ]);

                Log::info('InstagramHandler: Message marked as read', [
                    'message_id' => $message->id,
                    'external_id' => $messageId,
                    'read_at' => $message->read_at,
                ]);

                // Broadcast the message update
                broadcast(new MessageUpdated($message));

                if($message->conversation->last_message->id == $message->id) {
                    broadcast(new ConversationUpdated($message->conversation));
                }
            } else {
                Log::warning('InstagramHandler: Message not found for read status', [
                    'external_id' => $messageId,
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle read status', [
                'message_id' => $messageId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Queue-side entry point, for plain attachments (image/video/audio/file)
     * only. A shared post or reel is never mirrored: its bytes are the
     * poster's, not the conversation's, and the bubble carries the caption and
     * — for reels — a link to the post instead. See shareBody().
     */
    public function downloadMedia(Message $message): void
    {
        if (in_array($message->message_type, self::MEDIA_TYPES)) {
            $this->handleMediaMessage($message, $message->meta ?? []);
        }
    }

    private function handleMediaMessage(Message $message, array $payload)
    {
        $messaging = $payload['messaging'][0] ?? [];
        $attachments = $messaging['message']['attachments'] ?? [];

        if (empty($attachments)) {
            return;
        }

        $attachment = $attachments[0];
        $mediaUrl = $attachment['payload']['url'] ?? null;

        if (!$mediaUrl) {
            Log::warning('InstagramHandler: No media URL found in attachment', [
                'message_id' => $message->id,
                'attachment' => $attachment,
            ]);
            return;
        }

        try {
            $connection = $message->conversation->connection;
            $accessToken = $connection->credentials['access_token'] ?? null;

            if (!$accessToken) {
                Log::error('InstagramHandler: Missing access token', [
                    'connection_id' => $connection->id,
                ]);
                return;
            }

            // Download media from Instagram
            $response = Http::withToken($accessToken)->get($mediaUrl);

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to download media', [
                    'message_id' => $message->id,
                    'url' => $mediaUrl,
                    'status' => $response->status(),
                ]);
                return;
            }

            // Determine extension from mime type or URL
            $mimeType = $response->header('Content-Type');
            $extension = $this->getExtensionFromMimeType($mimeType) ?? 'bin';

            // Save media file
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $response->body());

            $message->update([
                'attachment' => $mediaPath,
            ]);

            Log::info('InstagramHandler: Media downloaded successfully', [
                'message_id' => $message->id,
                'media_path' => $mediaPath,
            ]);

        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        // Clean mime type (remove charset or other parameters)
        $cleanMimeType = explode(';', $mimeType)[0];

        return match($cleanMimeType) {
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
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
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            default => 'bin',
        };
    }

}
