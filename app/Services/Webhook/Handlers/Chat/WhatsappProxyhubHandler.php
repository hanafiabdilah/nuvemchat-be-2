<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Flow\FlowExecutor;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhook handler for the WhatsApp ProxyHub channel.
 *
 * Unlike W-API (which sends a flat `{ event: "webhookReceived", msgContent, sender, chat }`
 * payload), ProxyHub forwards the **native whatsmeow event** wrapped as `{ "event": {...} }`:
 *   - Message event:  event.Info { ID, Chat, Sender, SenderAlt, PushName, IsFromMe,
 *                     IsGroup, Timestamp, Type } + event.Message { conversation | imageMessage | ... }
 *   - Receipt event:  event.MessageIDs[], event.Type (delivered/read/played), event.MessageSource
 *
 * Identities use LID addressing; the real phone number is in `SenderAlt`
 * (e.g. `6282122787699:73@s.whatsapp.net`).
 */
class WhatsappProxyhubHandler implements ChatHandlerInterface
{
    public function handle(Connection $connection, array $payload)
    {
        // ProxyHub wraps each whatsmeow event as:
        //   { event: {...}, instanceName, type: "Message"|"Receipt"|"Presence"|..., userID }
        // The top-level `type` is the authoritative discriminator.
        $type = $payload['type'] ?? null;
        $event = $payload['event'] ?? $payload['data'] ?? $payload;

        if (! is_array($event)) {
            Log::warning('WhatsappProxyhubHandler: event is not an object', ['value_type' => gettype($event)]);
            return;
        }

        $isMessage = $type === 'Message' || (isset($event['Info']) && array_key_exists('Message', $event));
        $isReceipt = $type === 'Receipt' || isset($event['MessageIDs']);

        if ($isMessage) {
            $info = $event['Info'] ?? [];

            if ($info['IsGroup'] ?? false) {
                Log::info('WhatsappProxyhubHandler: skipping group message');
                return;
            }

            if ($info['IsFromMe'] ?? false) {
                $this->handleOwnMessage($connection, $event);
                return;
            }

            $this->handleReceived($connection, $event);
            return;
        }

        if ($isReceipt) {
            $this->handleReceipt($connection, $event);
            return;
        }

        // Presence / connection / unknown events — connection status is handled by
        // polling status-instance, so just log to capture new event types.
        Log::info('WhatsappProxyhubHandler: unhandled event', [
            'connection_id' => $connection->id,
            'type' => $type,
            'keys' => array_keys($event),
        ]);
    }

    private function handleReceived(Connection $connection, array $event)
    {
        $info = $event['Info'] ?? [];
        $messageId = $info['ID'] ?? null;
        $phone = $this->getContactExternalId($event);
        $contactName = $this->getContactName($event) ?: $phone;
        $messageType = $this->getMessageType($event);

        if (! $messageId || ! $phone) {
            Log::warning('WhatsappProxyhubHandler: missing required fields', [
                'message_id' => $messageId,
                'phone' => $phone,
            ]);
            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function () use ($connection, $event, $messageId, $phone, $contactName, $messageType, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $phone, $contactName, $phone);

            $conversation = Conversation::where('external_id', $phone)
                ->where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending])
                ->first();

            if (! $conversation) {
                $conversation = Conversation::create([
                    'contact_id' => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id' => $phone,
                    'status' => ConversationStatus::Pending,
                ]);
                $isNewConversation = true;
                $conversationForWelcome = $conversation;
            }

            if ($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) {
                Log::info('WhatsappProxyhubHandler: duplicate message ignored', ['message_id' => $messageId]);
                return null;
            }

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($event),
                'sent_at' => $this->getMessageSentAt($event),
                'delivery_at' => $this->getMessageSentAt($event),
                'meta' => $event,
            ]);
        });

        if (! $message) {
            return;
        }

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($message->conversation->load('contact')));

        $flowExecutor = new FlowExecutor();

        if ($isNewConversation && $conversationForWelcome) {
            if ($connection->flow_id) {
                try {
                    $flowExecutor->startFlow($conversationForWelcome);
                } catch (\Throwable $th) {
                    Log::error('WhatsappProxyhubHandler: failed to start flow', ['error' => $th->getMessage()]);
                }
            }
        } else {
            try {
                $flowExecutor->resumeFlow($message->conversation, $this->getMessageBody($event) ?? '');
            } catch (\Throwable $th) {
                Log::error('WhatsappProxyhubHandler: failed to resume flow', ['error' => $th->getMessage()]);
            }
        }
    }

    /**
     * Echo of a message sent from the connected phone itself (IsFromMe). For
     * messages we sent through the API the row already exists (matched by
     * external_id); otherwise we record the outgoing message.
     */
    private function handleOwnMessage(Connection $connection, array $event)
    {
        $info = $event['Info'] ?? [];
        $messageId = $info['ID'] ?? null;
        $phone = $this->getContactExternalId($event);

        if (! $messageId || ! $phone) {
            return;
        }

        if (Message::where('external_id', $messageId)->exists()) {
            return; // already recorded (e.g. sent via our API)
        }

        $message = DB::transaction(function () use ($connection, $event, $messageId, $phone) {
            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('external_id', $phone)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending])
                ->first();

            if (! $conversation) {
                $contact = Contact::createFromExternalData($connection, $phone, $phone, $phone);
                $conversation = Conversation::create([
                    'contact_id' => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id' => $phone,
                    'status' => ConversationStatus::Pending,
                ]);
            }

            return $conversation->messages()->updateOrCreate(
                ['external_id' => $messageId],
                [
                    'sender_type' => SenderType::Outgoing,
                    'message_type' => $this->getMessageType($event),
                    'body' => $this->getMessageBody($event),
                    'sent_at' => $this->getMessageSentAt($event),
                    'meta' => $event,
                ],
            );
        });

        if ($message) {
            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    /**
     * Delivery / read receipts. whatsmeow Type: "" or "delivered" → delivered;
     * "read"/"read-self"/"played" → read.
     */
    private function handleReceipt(Connection $connection, array $event)
    {
        $ids = $event['MessageIDs'] ?? [];
        $type = strtolower((string) ($event['Type'] ?? ''));
        $column = in_array($type, ['read', 'read-self', 'played'], true) ? 'read_at'
            : (in_array($type, ['', 'delivered', 'delivery'], true) ? 'delivery_at' : null);

        if (! $column || empty($ids)) {
            Log::info('WhatsappProxyhubHandler: receipt ignored', ['type' => $type, 'ids' => $ids]);
            return;
        }

        $timestamp = isset($event['Timestamp']) ? Carbon::parse($event['Timestamp']) : Carbon::now();

        foreach ($ids as $externalId) {
            $message = Message::whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
                ->where('external_id', $externalId)
                ->where('sender_type', SenderType::Outgoing)
                ->first();

            if ($message && $message->{$column} === null) {
                $message->update([$column => $timestamp]);
                broadcast(new MessageUpdated($message));
            }
        }
    }

    // --- ChatHandlerInterface (operate on the whatsmeow `event` object) ------

    public function getConversationId(array $event): ?string
    {
        return $this->getContactExternalId($event);
    }

    public function getMessageId(array $event): ?string
    {
        return $event['Info']['ID'] ?? null;
    }

    public function getMessageBody(array $event): ?string
    {
        $m = $event['Message'] ?? [];

        return $m['conversation']
            ?? $m['extendedTextMessage']['text']
            ?? $m['imageMessage']['caption']
            ?? $m['videoMessage']['caption']
            ?? $m['documentMessage']['caption']
            ?? $m['documentWithCaptionMessage']['message']['documentMessage']['caption']
            ?? null;
    }

    public function getMessageType(array $event): MessageType
    {
        $m = $event['Message'] ?? [];

        return match (true) {
            isset($m['conversation']), isset($m['extendedTextMessage']) => MessageType::Text,
            isset($m['imageMessage']) => MessageType::Image,
            isset($m['videoMessage']) => MessageType::Video,
            isset($m['audioMessage']) => MessageType::Audio,
            isset($m['documentMessage']), isset($m['documentWithCaptionMessage']) => MessageType::Document,
            isset($m['stickerMessage']) => MessageType::Sticker,
            isset($m['locationMessage']) => MessageType::Location,
            default => MessageType::Unsupported,
        };
    }

    public function getMessageSentAt(array $event): Carbon
    {
        $ts = $event['Info']['Timestamp'] ?? null;

        return $ts ? Carbon::parse($ts) : Carbon::now();
    }

    public function getContactName(array $event): ?string
    {
        return $event['Info']['PushName'] ?? null;
    }

    public function getContactUsername(array $event): ?string
    {
        return $this->getContactExternalId($event);
    }

    public function getContactExternalId(array $event): ?string
    {
        $info = $event['Info'] ?? [];

        // Prefer the phone JID (SenderAlt) over the LID (Sender/Chat).
        return $this->extractPhone($info['SenderAlt'] ?? null)
            ?? $this->extractPhone($info['Sender'] ?? null)
            ?? $this->extractPhone($info['Chat'] ?? null);
    }

    /**
     * Normalise a WhatsApp JID to its bare user id.
     * "6282122787699:73@s.whatsapp.net" → "6282122787699"; "123@lid" → "123".
     */
    private function extractPhone(?string $jid): ?string
    {
        if (! $jid) {
            return null;
        }

        $user = explode('@', $jid)[0];   // strip server
        $user = explode(':', $user)[0];  // strip device id
        $user = explode('.', $user)[0];  // strip any agent suffix

        return $user !== '' ? $user : null;
    }
}
