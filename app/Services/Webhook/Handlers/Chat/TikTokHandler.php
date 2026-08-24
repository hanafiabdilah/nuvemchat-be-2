<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Connection\TikTok\TikTokMessagingClient;
use App\Services\Conversation\LastAgentRouter;
use App\Services\Flow\FlowExecutor;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use App\Services\Webhook\Contracts\DownloadsInboundMedia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handles TikTok Business Messaging webhook events. The payload is the raw
 * event envelope: { event, user_openid, content } where `content` arrives as a
 * JSON *string*. Events: im_receive_msg (user → business), im_send_msg (echo
 * of business → user, whether sent via our API or the TikTok app directly),
 * im_mark_read_msg (user read the conversation).
 */
class TikTokHandler implements ChatHandlerInterface, DownloadsInboundMedia
{
    public function getConversationId(array $payload): ?string
    {
        return $this->content($payload)['conversation_id'] ?? null;
    }

    public function getMessageId(array $payload): ?string
    {
        return $this->content($payload)['message_id'] ?? null;
    }

    public function getMessageBody(array $payload): ?string
    {
        $content = $this->content($payload);

        return match ($content['type'] ?? null) {
            'text' => $content['text']['body'] ?? null,
            // A shared video/post arrives as an embed URL — store it as the
            // body so the chat renders a clickable link.
            'share_post' => $content['share_post']['embed_url'] ?? null,
            default => null,
        };
    }

    public function getMessageType(array $payload): MessageType
    {
        return match ($this->content($payload)['type'] ?? null) {
            'text', 'share_post' => MessageType::Text,
            'image' => MessageType::Image,
            'sticker' => MessageType::Sticker,
            default => MessageType::Unsupported,
        };
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        $timestamp = $this->content($payload)['timestamp'] ?? null;

        // TikTok timestamps are in milliseconds.
        return $timestamp ? Carbon::createFromTimestampMs($timestamp) : Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        // TikTok only exposes the username, no display name.
        return $this->getContactUsername($payload);
    }

    public function getContactUsername(array $payload): ?string
    {
        $content = $this->content($payload);

        return $this->isEcho($payload)
            ? ($content['to'] ?? null)
            : ($content['from'] ?? null);
    }

    public function getContactExternalId(array $payload): ?string
    {
        $content = $this->content($payload);

        // The contact is the conversation partner: the sender on inbound
        // messages, the recipient on outbound echoes.
        return $this->isEcho($payload)
            ? ($content['to_user']['id'] ?? null)
            : ($content['from_user']['id'] ?? null);
    }

    public function getRepliedMessageId(array $payload): ?string
    {
        return $this->content($payload)['referenced_message_info']['referenced_message_id'] ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        switch ($payload['event'] ?? null) {
            case 'im_receive_msg':
            case 'im_send_msg':
                $this->handleReceived($connection, $payload);
                break;

            case 'im_mark_read_msg':
                $this->handleMarkRead($connection, $payload);
                break;

            default:
                Log::warning('TikTokHandler: Unsupported event type', [
                    'event' => $payload['event'] ?? null,
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
        $isOutgoing = $this->isEcho($payload);

        if (!$conversationId || !$messageId || !$contactExternalId) {
            Log::warning('TikTokHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'contact_external_id' => $contactExternalId,
            ]);
            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function() use ($connection, $payload, $conversationId, $messageId, $messageType, $contactExternalId, $contactName, $isOutgoing, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $contactExternalId, $contactName ?: $contactExternalId, $this->getContactUsername($payload));

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

            // Echoes of messages our own send handler already saved arrive a
            // moment later with the same message_id — skip them here.
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
                    Log::warning('TikTokHandler: Replied message not found in database', [
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
                'meta' => $payload,
            ]);
        });

        if($message){
            if($messageType === MessageType::Image) {
                DownloadInboundMedia::dispatchFor($message);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            // Only process flow for incoming messages (from user, not from business)
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
                        Log::error('TikTokHandler: Failed to start flow', [
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
                    Log::error('TikTokHandler: Failed to resume flow', [
                        'conversation_id' => $message->conversation->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * A user marked the conversation as read: TikTok reports one timestamp,
     * not per-message receipts, so flag every outgoing message up to it.
     */
    private function handleMarkRead(Connection $connection, array $payload)
    {
        $content = $this->content($payload);
        $conversationId = $content['conversation_id'] ?? null;
        $lastReadTimestamp = $content['read']['last_read_timestamp'] ?? null;
        $fromUserId = $content['from_user']['id'] ?? null;
        $businessId = $connection->credentials['business_id'] ?? null;

        // Ignore our own read events — only the user's reads matter here.
        if (!$conversationId || !$lastReadTimestamp || ($fromUserId && $fromUserId === $businessId)) {
            return;
        }

        $conversation = Conversation::where('external_id', $conversationId)
            ->where('connection_id', $connection->id)
            ->orderByDesc('id')
            ->first();

        if (!$conversation) {
            Log::warning('TikTokHandler: Conversation not found for read status', [
                'external_id' => $conversationId,
            ]);
            return;
        }

        $readAt = Carbon::createFromTimestampMs($lastReadTimestamp);

        $updated = $conversation->messages()
            ->where('sender_type', SenderType::Outgoing)
            ->whereNull('read_at')
            ->where('sent_at', '<=', $readAt)
            ->get();

        foreach ($updated as $message) {
            $message->update(['read_at' => $readAt]);
        }

        if ($updated->isNotEmpty()) {
            // One broadcast for the newest message is enough for the SPA to
            // re-render the ticks; per-message events would flood the socket.
            broadcast(new MessageUpdated($updated->sortByDesc('sent_at')->first()));
            broadcast(new ConversationUpdated($conversation));
        }
    }

    /**
     * Queue-side entry point: the event envelope was stored on the message, so
     * the download URL can still be minted from its media id.
     */
    public function downloadMedia(Message $message): void
    {
        $this->handleMediaMessage($message, $message->conversation->connection, $message->meta ?? []);
    }

    private function handleMediaMessage(Message $message, Connection $connection, array $payload)
    {
        try {
            $content = $this->content($payload);
            $mediaId = $content['image']['media_id'] ?? null;

            if (!$mediaId) {
                Log::warning('TikTokHandler: Missing media_id in image payload', [
                    'message_id' => $message->id,
                ]);
                return;
            }

            $client = new TikTokMessagingClient($connection);

            $downloadUrl = $client->mediaDownloadUrl(
                $content['conversation_id'],
                $content['message_id'],
                $mediaId
            );

            $response = $client->downloadMedia($downloadUrl);

            if ($response->failed()) {
                $message->update([
                    'error' => 'Failed to download TikTok media (HTTP ' . $response->status() . ')',
                ]);
                return;
            }

            $extension = $this->getExtensionFromContentType($response->header('Content-Type'));
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;

            Storage::disk('local')->put($mediaPath, $response->body());

            $message->update([
                'attachment' => $mediaPath,
            ]);
        } catch (\Throwable $th) {
            Log::error('TikTokHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'error' => $th->getMessage(),
            ]);

            $message->update([
                'error' => 'Failed to download TikTok media',
            ]);
        }
    }

    /**
     * The `content` field of the event envelope is a JSON string.
     */
    private function content(array $payload): array
    {
        $content = $payload['content'] ?? [];

        if (is_string($content)) {
            $content = json_decode($content, true) ?: [];
        }

        return $content;
    }

    /**
     * im_send_msg events are echoes of business → user messages, sent either
     * through our API or directly from the TikTok app.
     */
    private function isEcho(array $payload): bool
    {
        return ($payload['event'] ?? null) === 'im_send_msg';
    }

    private function getExtensionFromContentType(?string $contentType): string
    {
        return match (strtolower(trim(explode(';', (string) $contentType)[0]))) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            default => 'jpg',
        };
    }
}
