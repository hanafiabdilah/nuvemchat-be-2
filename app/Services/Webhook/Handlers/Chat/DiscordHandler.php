<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Jobs\DownloadInboundMedia;
use App\Events\MessageUpdated;
use App\Jobs\SyncContactPhoto;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Flow\FlowExecutor;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use App\Services\Webhook\Contracts\DownloadsInboundMedia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Discord inbound events. There is no webhook: the discord:gateway daemon
 * receives Gateway dispatches and forwards them here as
 * `['t' => 'MESSAGE_CREATE'|'MESSAGE_UPDATE'|'MESSAGE_DELETE', 'd' => <event>]`.
 * Scope is bot DMs (DIRECT_MESSAGES intent) — DM content is exempt from the
 * privileged MESSAGE_CONTENT intent. The DM channel id is the conversation's
 * external_id; the Discord user id is the contact's external_id.
 */
class DiscordHandler implements ChatHandlerInterface, DownloadsInboundMedia
{
    /** Message types whose bytes are fetched after the message is broadcast. */
    private const MEDIA_TYPES = [
        MessageType::Image,
        MessageType::Video,
        MessageType::Document,
        MessageType::Audio,
    ];

    public function getConversationId(array $payload): ?string
    {
        return isset($payload['d']['channel_id']) ? (string) $payload['d']['channel_id'] : null;
    }

    public function getMessageId(array $payload): ?string
    {
        return isset($payload['d']['id']) ? (string) $payload['d']['id'] : null;
    }

    public function getMessageBody(array $payload): ?string
    {
        $content = $payload['d']['content'] ?? '';

        return $content !== '' ? $content : null;
    }

    public function getMessageType(array $payload): MessageType
    {
        $data = $payload['d'] ?? [];

        if (!empty($data['sticker_items'])) {
            return MessageType::Sticker;
        }

        if (!empty($data['attachments'][0])) {
            $contentType = (string) ($data['attachments'][0]['content_type'] ?? '');

            return match (true) {
                str_starts_with($contentType, 'image/') => MessageType::Image,
                str_starts_with($contentType, 'video/') => MessageType::Video,
                str_starts_with($contentType, 'audio/') => MessageType::Audio,
                default => MessageType::Document,
            };
        }

        if (($data['content'] ?? '') !== '') {
            return MessageType::Text;
        }

        return MessageType::Unsupported;
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        $timestamp = $payload['d']['timestamp'] ?? null;

        if ($timestamp) {
            try {
                return Carbon::parse($timestamp);
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }

    public function getContactName(array $payload): ?string
    {
        $author = $payload['d']['author'] ?? [];

        return $author['global_name'] ?? $author['username'] ?? ($author['id'] ?? null);
    }

    public function getContactUsername(array $payload): ?string
    {
        return $payload['d']['author']['username'] ?? null;
    }

    public function getContactExternalId(array $payload): ?string
    {
        return isset($payload['d']['author']['id']) ? (string) $payload['d']['author']['id'] : null;
    }

    public function isOutgoingMessage(array $payload): bool
    {
        return false; // echoes are resolved against credentials in handle()
    }

    public function getRepliedMessageId(array $payload): ?string
    {
        $data = $payload['d'] ?? [];

        return $data['referenced_message']['id']
            ?? $data['message_reference']['message_id']
            ?? null;
    }

    public function handle(Connection $connection, array $payload)
    {
        $eventType = $payload['t'] ?? null;
        $data = $payload['d'] ?? [];

        // Defensive: the daemon only subscribes to DMs, but never ingest guild
        // traffic or webhook-authored messages if any slips through.
        if (!empty($data['guild_id']) || !empty($data['webhook_id'])) {
            return;
        }

        switch ($eventType) {
            case 'MESSAGE_CREATE':
                $this->handleReceived($connection, $payload);
                break;

            case 'MESSAGE_UPDATE':
                $this->handleMessageEdit($connection, $payload);
                break;

            case 'MESSAGE_DELETE':
                $this->handleMessageDelete($connection, $payload);
                break;

            default:
                Log::warning('DiscordHandler: Unsupported event type', [
                    'event' => $eventType,
                ]);
                break;
        }
    }

    private function handleReceived(Connection $connection, array $payload)
    {
        $data = $payload['d'] ?? [];
        $botUserId = (string) ($connection->credentials['bot_user_id'] ?? '');
        $authorId = $this->getContactExternalId($payload);
        $isOutgoing = $authorId !== null && $authorId === $botUserId;

        // Ignore other bots entirely — only our own messages count as echoes.
        if (!$isOutgoing && !empty($data['author']['bot'])) {
            return;
        }

        $conversationId = $this->getConversationId($payload);
        $messageId = $this->getMessageId($payload);
        $messageType = $this->getMessageType($payload);

        if (!$conversationId || !$messageId || !$authorId) {
            Log::warning('DiscordHandler: Missing required data in payload', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'author_id' => $authorId,
            ]);
            return;
        }

        if ($messageType === MessageType::Unsupported && $this->getMessageBody($payload) === null) {
            return; // system messages (pins, calls, …) carry nothing to store
        }

        // Echo in a DM only names the bot as author — the customer contact is
        // unknown from the payload, so echoes only attach to conversations that
        // already exist (i.e. the user has messaged first).
        if ($isOutgoing) {
            $conversation = Conversation::where('external_id', $conversationId)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
                ->first();

            if (!$conversation) {
                return;
            }

            if ($conversation->messages()->where('external_id', $messageId)->exists()) {
                return; // echo of a message the send handler already stored
            }

            $message = $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => SenderType::Outgoing,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);

            if (in_array($messageType, self::MEDIA_TYPES)) {
                DownloadInboundMedia::dispatchFor($message);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($conversation->load('contact')));

            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function () use ($connection, $payload, $conversationId, $messageId, $messageType, $authorId, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData(
                $connection,
                $authorId,
                $this->getContactName($payload) ?? $authorId,
                $this->getContactUsername($payload),
            );

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
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($payload),
                'replied_message_id' => $repliedMessageId,
                'sent_at' => $this->getMessageSentAt($payload),
                'delivery_at' => $this->getMessageSentAt($payload),
                'meta' => $payload,
            ]);
        });

        if ($message) {
            if (in_array($messageType, self::MEDIA_TYPES)) {
                DownloadInboundMedia::dispatchFor($message);
            }

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));

            $flowExecutor = new FlowExecutor();

            if ($isNewConversation && $conversationForWelcome) {
                if ($connection->flow_id) {
                    try {
                        $flowExecutor->startFlow($conversationForWelcome);
                    } catch (\Throwable $th) {
                        Log::error('DiscordHandler: Failed to start flow', [
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
                    Log::error('DiscordHandler: Failed to resume flow', [
                        'conversation_id' => $message->conversation->id,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }
    }

    private function handleMessageEdit(Connection $connection, array $payload)
    {
        $data = $payload['d'] ?? [];
        $messageId = isset($data['id']) ? (string) $data['id'] : null;

        if (!$messageId || !array_key_exists('content', $data)) {
            return; // embed-only updates (link previews) don't change the body
        }

        try {
            $message = Message::whereHas('conversation', function ($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->where('external_id', $messageId)
            ->first();

            if (!$message) {
                return;
            }

            $editedAt = isset($data['edited_timestamp']) && $data['edited_timestamp']
                ? Carbon::parse($data['edited_timestamp'])
                : Carbon::now();

            $message->update([
                'body' => ($data['content'] ?? '') !== '' ? $data['content'] : $message->body,
                'edited_at' => $editedAt,
                'meta' => array_merge($message->meta ?? [], [
                    'last_edit_payload' => $payload,
                ]),
            ]);

            broadcast(new MessageUpdated($message));

            if ($message->conversation->last_message?->id == $message->id) {
                broadcast(new ConversationUpdated($message->conversation));
            }
        } catch (\Throwable $th) {
            Log::error('DiscordHandler: Failed to handle message edit', [
                'message_id' => $messageId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    private function handleMessageDelete(Connection $connection, array $payload)
    {
        $data = $payload['d'] ?? [];
        $messageId = isset($data['id']) ? (string) $data['id'] : null;

        if (!$messageId) {
            return;
        }

        try {
            $message = Message::whereHas('conversation', function ($query) use ($connection) {
                $query->where('connection_id', $connection->id);
            })
            ->where('external_id', $messageId)
            ->first();

            if (!$message) {
                return;
            }

            $message->update([
                'unsend_at' => Carbon::now(),
                'meta' => array_merge($message->meta ?? [], [
                    'delete_payload' => $payload,
                ]),
            ]);

            broadcast(new MessageUpdated($message));

            if ($message->conversation->last_message?->id == $message->id) {
                broadcast(new ConversationUpdated($message->conversation));
            }
        } catch (\Throwable $th) {
            Log::error('DiscordHandler: Failed to handle message delete', [
                'message_id' => $messageId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Queue-side entry point: the gateway event was stored on the message, and
     * its attachment URL is a pre-signed CDN link good for hours.
     */
    public function downloadMedia(Message $message): void
    {
        $this->handleMediaMessage($message, $message->meta ?? []);
    }

    private function handleMediaMessage(Message $message, array $payload)
    {
        $attachment = $payload['d']['attachments'][0] ?? null;
        $mediaUrl = $attachment['url'] ?? null;

        if (!$mediaUrl) {
            return;
        }

        try {
            // Attachment URLs are pre-signed CDN links — plain GET, no auth.
            $response = Http::get($mediaUrl);

            if (!$response->successful()) {
                Log::error('DiscordHandler: Failed to download media', [
                    'message_id' => $message->id,
                    'url' => $mediaUrl,
                    'status' => $response->status(),
                ]);
                return;
            }

            $filename = $attachment['filename'] ?? 'attachment.bin';
            $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $response->body());

            $message->update([
                'attachment' => $mediaPath,
                'meta' => array_merge($message->meta ?? [], ['filename' => $filename]),
            ]);
        } catch (\Throwable $th) {
            Log::error('DiscordHandler: Failed to handle media message', [
                'message_id' => $message->id,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
