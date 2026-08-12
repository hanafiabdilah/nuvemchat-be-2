<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Jobs\DownloadInboundMedia;
use App\Jobs\SyncContactPhoto;
use App\Jobs\SyncGroupMetadata;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\Contact\Photo\ContactPhotoSyncer;
use App\Services\Conversation\GroupConversationService;
use App\Services\Flow\FlowExecutor;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use App\Services\Webhook\Contracts\DownloadsInboundMedia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Inbound webhook handler for the WhatsApp API Way channel.
 *
 * Unlike W-API (which sends a flat `{ event: "webhookReceived", msgContent, sender, chat }`
 * payload), API Way forwards the **native whatsmeow event** wrapped as `{ "event": {...} }`:
 *   - Message event:  event.Info { ID, Chat, Sender, SenderAlt, PushName, IsFromMe,
 *                     IsGroup, Timestamp, Type } + event.Message { conversation | imageMessage | ... }
 *   - Receipt event:  event.MessageIDs[], event.Type (delivered/read/played), event.MessageSource
 *
 * Identities use LID addressing; the real phone number is in `SenderAlt`
 * (e.g. `6282122787699:73@s.whatsapp.net`).
 */
class WhatsappApiwayHandler implements ChatHandlerInterface, DownloadsInboundMedia
{
    /** Message types whose bytes are fetched after the message is broadcast. */
    private const MEDIA_TYPES = [
        MessageType::Image,
        MessageType::Video,
        MessageType::Audio,
        MessageType::Document,
        MessageType::Sticker,
    ];

    /**
     * The Message fields that are encryption/session plumbing rather than
     * content: the group sender-key hand-out, and the context envelope holding
     * the device list and message secret. A node made up of nothing but these
     * has no body to render.
     */
    private const ENVELOPE_KEYS = [
        'messageContextInfo',
        'senderKeyDistributionMessage',
    ];

    /** waE2E.ProtocolMessage_REVOKE — "delete for everyone". */
    private const PROTOCOL_TYPE_REVOKE = 0;

    public function handle(Connection $connection, array $payload)
    {
        // API Way wraps each whatsmeow event as:
        //   { event: {...}, instanceName, type: "Message"|"Receipt"|"Presence"|..., userID }
        // The top-level `type` is the authoritative discriminator.
        $type = $payload['type'] ?? null;
        $event = $payload['event'] ?? $payload['data'] ?? $payload;

        if (! is_array($event)) {
            Log::warning('WhatsappApiwayHandler: event is not an object', ['value_type' => gettype($event)]);

            return;
        }

        $isMessage = $type === 'Message' || (isset($event['Info']) && array_key_exists('Message', $event));
        $isReceipt = $type === 'Receipt' || isset($event['MessageIDs']);

        if ($isMessage) {
            $info = $event['Info'] ?? [];

            // WhatsApp device-level control traffic (disappearing-message sync,
            // app-state/history sync, key exchange). It carries no body, so
            // persisting it would only add blank "unsupported" rows — every
            // type but REVOKE is dropped.
            if (isset($event['Message']['protocolMessage'])) {
                $protocol = $event['Message']['protocolMessage'];

                // "Delete for everyone" is not an event of its own: it arrives
                // as a plain message whose only payload is a REVOKE protocol
                // node naming the victim in key.ID.
                if ($this->isRevoke($protocol)) {
                    $this->handleRevoke($connection, $event, $protocol);

                    return;
                }

                Log::info('WhatsappApiwayHandler: skipping protocol message', [
                    'connection_id' => $connection->id,
                    'protocol_type' => $protocol['type'] ?? null,
                    'message_id' => $info['ID'] ?? null,
                ]);

                return;
            }

            // In a group whatsmeow delivers each message TWICE, under the same
            // ID: first the sender-key distribution (encryption plumbing, no
            // body), then the real content. Storing the first one produced a
            // blank "unsupported" bubble, and — racing the second — a duplicate
            // conversation, because both events missed the same find-or-create
            // SELECT. Envelope-only events carry nothing, so they are dropped.
            if ($this->isEnvelopeOnly($event['Message'] ?? [])) {
                Log::info('WhatsappApiwayHandler: skipping envelope-only message', [
                    'connection_id' => $connection->id,
                    'message_id' => $info['ID'] ?? null,
                    'keys' => array_keys($event['Message'] ?? []),
                ]);

                return;
            }

            // `status@broadcast` (other people's Stories) and broadcast-list
            // JIDs are one-to-many pseudo-chats, not conversations — whatsmeow
            // flags them IsGroup, so without this they were landing in the
            // inbox as group threads. Recipients of a broadcast list still get
            // the message as a normal 1:1 chat, so nothing is lost by dropping
            // the sender-side echo.
            if ($this->isBroadcastChat($info['Chat'] ?? null)) {
                Log::info('WhatsappApiwayHandler: skipping broadcast chat', [
                    'connection_id' => $connection->id,
                    'chat' => $info['Chat'] ?? null,
                    'message_id' => $info['ID'] ?? null,
                ]);

                return;
            }

            // A reaction is not a message of its own — it decorates one that
            // already exists, in a private chat or a group alike.
            if (isset($event['Message']['reactionMessage'])) {
                $this->handleReaction($connection, $event);

                return;
            }

            if ($info['IsGroup'] ?? false) {
                $this->handleGroupMessage($connection, $event);

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

        // Group metadata events: GroupInfo (subject changed) and JoinedGroup
        // (this phone entered a group, carries the current subject).
        if (in_array($type, ['GroupInfo', 'JoinedGroup'], true)) {
            $this->handleGroupMetadata($connection, $event);

            return;
        }

        // whatsmeow's Picture event covers a person's avatar and a group's
        // photo alike; Remove distinguishes a change from a deletion.
        if ($type === 'Picture') {
            $this->handlePictureChange($connection, $event);

            return;
        }

        // Presence / connection / unknown events — connection status is handled by
        // polling status-instance, so just log to capture new event types.
        Log::info('WhatsappApiwayHandler: unhandled event', [
            'connection_id' => $connection->id,
            'type' => $type,
            'keys' => array_keys($event),
        ]);
    }

    private function handleReceived(Connection $connection, array $event)
    {
        $info = $event['Info'] ?? [];
        $messageId = $info['ID'] ?? null;
        $lid = $this->getContactLid($event);
        $phone = $this->resolvePhoneFromJids($connection, $this->partnerJids($event));
        $contactName = $this->getContactName($event) ?: $phone;
        $messageType = $this->getMessageType($event);

        if (! $messageId || ! $phone) {
            // An unresolvable @lid means a re-delivery for someone we have
            // never seen with a phone. Keying a contact off the @lid would
            // create an unrepliable ghost thread, so drop it instead.
            Log::warning('WhatsappApiwayHandler: missing required fields', [
                'message_id' => $messageId,
                'phone' => $phone,
                'lid' => $lid,
                'unavailable_request_id' => $event['UnavailableRequestID'] ?? null,
            ]);

            return;
        }

        if ($this->alreadyStored($connection, $messageId)) {
            Log::info('WhatsappApiwayHandler: message already stored on this connection', [
                'message_id' => $messageId,
                'unavailable_request_id' => $event['UnavailableRequestID'] ?? null,
            ]);

            return;
        }

        $isNewConversation = false;
        $conversationForWelcome = null;

        $message = DB::transaction(function () use ($connection, $event, $messageId, $phone, $lid, $contactName, $messageType, &$isNewConversation, &$conversationForWelcome) {
            $contact = Contact::createFromExternalData($connection, $phone, $contactName, $phone);
            $this->rememberLid($contact, $lid);
            SyncContactPhoto::dispatchIfStale($contact, $connection);

            $conversation = Conversation::where('external_id', $phone)
                ->where('contact_id', $contact->id)
                ->where('connection_id', $connection->id)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending, ConversationStatus::AiHandling])
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
                Log::info('WhatsappApiwayHandler: duplicate message ignored', ['message_id' => $messageId]);

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

        if (in_array($messageType, self::MEDIA_TYPES, true)) {
            DownloadInboundMedia::dispatchFor($message);
        }

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($message->conversation->load('contact')));

        $flowExecutor = new FlowExecutor;

        if ($isNewConversation && $conversationForWelcome) {
            if ($connection->flow_id) {
                try {
                    $flowExecutor->startFlow($conversationForWelcome);
                } catch (\Throwable $th) {
                    Log::error('WhatsappApiwayHandler: failed to start flow', ['error' => $th->getMessage()]);
                }
            }
        } else {
            try {
                $flowExecutor->resumeFlow($message->conversation, $this->getMessageBody($event) ?? '');
            } catch (\Throwable $th) {
                Log::error('WhatsappApiwayHandler: failed to resume flow', ['error' => $th->getMessage()]);
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
        $lid = $this->getContactLid($event);
        $phone = $this->resolvePhoneFromJids($connection, $this->partnerJids($event));

        if (! $messageId || ! $phone) {
            return;
        }

        if ($this->alreadyStored($connection, $messageId)) {
            return; // already recorded (e.g. sent via our API, or re-delivered)
        }

        $message = DB::transaction(function () use ($connection, $event, $messageId, $phone, $lid) {
            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('external_id', $phone)
                ->whereIn('status', [ConversationStatus::Active, ConversationStatus::Pending, ConversationStatus::AiHandling])
                ->first();

            if (! $conversation) {
                $contact = Contact::createFromExternalData($connection, $phone, $phone, $phone);
                $this->rememberLid($contact, $lid);
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
            if (in_array($message->message_type, self::MEDIA_TYPES, true)) {
                DownloadInboundMedia::dispatchFor($message);
            }
            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation));
        }
    }

    /**
     * Message posted in a WhatsApp group. Follows the channel-agnostic model in
     * GroupConversationService: conversation keyed by the full group JID
     * (…@g.us), the group itself is an is_group contact, and each incoming
     * message records its real sender (phone from SenderAlt — identities use
     * LID addressing) plus a conversation_participants row. Flows never run.
     *
     * whatsmeow Message events carry no group subject, so a new group is created
     * with the JID local part as a placeholder and SyncGroupMetadata reads the
     * real subject from the core straight after (GroupInfo/JoinedGroup webhooks
     * only ever fire on a rename or a join, never for groups that predate the
     * connection). A manual rename (name_locked) still wins over both.
     */
    private function handleGroupMessage(Connection $connection, array $event): void
    {
        $info = $event['Info'] ?? [];
        $groupJid = $info['Chat'] ?? null;
        $messageId = $info['ID'] ?? null;
        $messageType = $this->getMessageType($event);
        $isFromMe = $info['IsFromMe'] ?? false;

        if (! $messageId || ! $groupJid) {
            Log::warning('WhatsappApiwayHandler: missing required group fields', [
                'message_id' => $messageId,
                'group_jid' => $groupJid,
            ]);

            return;
        }

        $senderPhone = null;
        $senderName = null;
        $senderLid = null;

        if (! $isFromMe) {
            // Sender JIDs only — Chat is the group here, never the member.
            $senderJids = [$info['SenderAlt'] ?? null, $info['Sender'] ?? null];
            $senderLid = $this->lidFromJids($senderJids);
            $senderPhone = $this->resolvePhoneFromJids($connection, $senderJids);

            if (! $senderPhone) {
                Log::warning('WhatsappApiwayHandler: group message without an identifiable sender', [
                    'message_id' => $messageId,
                    'lid' => $senderLid,
                ]);

                return;
            }

            $senderName = ($info['PushName'] ?? null) ?: $senderPhone;
        }

        if ($this->alreadyStored($connection, $messageId)) {
            Log::info('WhatsappApiwayHandler: group message already stored on this connection', [
                'message_id' => $messageId,
            ]);

            return;
        }

        // The group's own contact is resolved (and refreshed) before anything
        // else, because a removed group must keep its identity: whatsmeow never
        // carries the group's picture on a message event, and the subject comes
        // from /v1/group/group-metadata — both keep running while the messages
        // are dropped.
        $groupContact = GroupConversationService::resolveGroupContact(
            $connection,
            $groupJid,
            $this->groupFallbackTitle($groupJid),
            renameIfChanged: false,
        );

        SyncContactPhoto::dispatchIfStale($groupContact, $connection);
        SyncGroupMetadata::dispatchIfStale($groupContact, $connection);

        if ($groupContact->isRemovedGroup()) {
            Log::info('WhatsappApiwayHandler: dropping message from a removed group', [
                'connection_id' => $connection->id,
                'group_jid' => $groupJid,
                'message_id' => $messageId,
            ]);

            return;
        }

        // Locked around the transaction, not inside it: concurrent webhooks for
        // this group must not each create their own conversation row.
        $message = GroupConversationService::lockChat($connection, $groupJid, fn () => DB::transaction(function () use ($connection, $event, $groupJid, $messageId, $messageType, $isFromMe, $senderPhone, $senderName, $senderLid) {
            $conversation = GroupConversationService::resolveConversation(
                $connection,
                $groupJid,
                $this->groupFallbackTitle($groupJid),
                renameIfChanged: false,
            );

            if ($conversation->messages()->where('external_id', $messageId)->lockForUpdate()->exists()) {
                Log::info('WhatsappApiwayHandler: duplicate group message ignored', ['message_id' => $messageId]);

                return null;
            }

            if ($isFromMe) {
                // Echo of a message sent from the phone (or already saved via
                // our API — caught by the duplicate check above).
                return $conversation->messages()->create([
                    'external_id' => $messageId,
                    'sender_type' => SenderType::Outgoing,
                    'message_type' => $messageType,
                    'body' => $this->getMessageBody($event),
                    'sent_at' => $this->getMessageSentAt($event),
                    'meta' => $event,
                ]);
            }

            $sender = Contact::createFromExternalData($connection, $senderPhone, $senderName, $senderPhone);
            $this->rememberLid($sender, $senderLid);
            SyncContactPhoto::dispatchIfStale($sender, $connection);
            GroupConversationService::addParticipant($conversation, $sender);

            return $conversation->messages()->create([
                'external_id' => $messageId,
                'contact_id' => $sender->id,
                'sender_type' => SenderType::Incoming,
                'message_type' => $messageType,
                'body' => $this->getMessageBody($event),
                'sent_at' => $this->getMessageSentAt($event),
                'delivery_at' => $this->getMessageSentAt($event),
                'meta' => $event,
            ]);
        }));

        if (! $message) {
            return;
        }

        if (in_array($messageType, self::MEDIA_TYPES, true)) {
            DownloadInboundMedia::dispatchFor($message);
        }

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($message->conversation->load('contact')));

        // No flow automation in groups (also guarded in FlowExecutor).
    }

    /**
     * REVOKE — "delete for everyone", from the customer or from the connected
     * phone alike. Nothing is removed on our side: the row is stamped
     * `unsend_at`, which is what every other channel does and what the panel
     * renders as "this message was deleted".
     *
     * The event is keyed on the *victim's* id (`key.ID`), not on `Info.ID` —
     * the revoke carries an id of its own that we never store.
     */
    private function handleRevoke(Connection $connection, array $event, array $protocol): void
    {
        $info = $event['Info'] ?? [];
        $targetExternalId = $this->messageKeyId($protocol['key'] ?? []);

        if (! $targetExternalId) {
            Log::warning('WhatsappApiwayHandler: revoke without a target', [
                'connection_id' => $connection->id,
                'message_id' => $info['ID'] ?? null,
            ]);

            return;
        }

        $message = Message::whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
            ->where('external_id', $targetExternalId)
            ->first();

        if (! $message) {
            // Deleting a message older than this connection's history, or one
            // that was dropped on the way in (broadcast chat, removed group).
            Log::info('WhatsappApiwayHandler: revoke target not found', [
                'connection_id' => $connection->id,
                'target_external_id' => $targetExternalId,
            ]);

            return;
        }

        // A re-delivered revoke must not push the deletion time forward, but it
        // still gets re-broadcast: the first one may have raced a panel that
        // was offline.
        if ($message->unsend_at === null) {
            $message->update([
                'unsend_at' => $this->parseTimestamp($info['Timestamp'] ?? null),
                'meta' => array_merge($message->meta ?? [], ['delete_payload' => $event]),
            ]);
        }

        broadcast(new MessageUpdated($message));

        // The list preview still shows the deleted text until the conversation
        // row is refreshed too.
        if ($message->conversation->last_message?->id === $message->id) {
            broadcast(new ConversationUpdated($message->conversation->load('contact')));
        }
    }

    /**
     * whatsmeow marshals the proto enum as its *number* in production payloads
     * (REVOKE is 0) and as its name in some builds, so both are accepted. A
     * marshaller that omits zero values leaves REVOKE with no `type` at all —
     * hence the last resort: a protocol node holding nothing but another
     * message's key is a revoke and nothing else.
     */
    private function isRevoke(array $protocol): bool
    {
        $type = $protocol['type'] ?? null;

        if (is_string($type)) {
            return strtoupper($type) === 'REVOKE';
        }

        if (is_int($type)) {
            return $type === self::PROTOCOL_TYPE_REVOKE;
        }

        return $type === null
            && array_keys(array_filter($protocol, fn ($node) => $node !== null)) === ['key'];
    }

    /**
     * The id inside a proto MessageKey. whatsmeow names the field `ID`; the
     * lowercase spellings are there in case a build marshals it differently.
     */
    private function messageKeyId(array $key): ?string
    {
        $id = $key['ID'] ?? $key['id'] ?? $key['Id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * A 👍 on an existing message. `Message.reactionMessage` carries the target
     * in `key.ID` and the emoji in `text`; an **empty** `text` is WhatsApp's
     * "reaction removed".
     *
     * `key.participant` names who sent the *target* message, not who reacted —
     * the reactor is in Info.Sender/SenderAlt, same as any other event.
     */
    private function handleReaction(Connection $connection, array $event): void
    {
        $info = $event['Info'] ?? [];
        $reaction = $event['Message']['reactionMessage'] ?? [];
        $targetExternalId = $reaction['key']['ID'] ?? null;
        $emoji = $reaction['text'] ?? '';
        $isFromMe = $info['IsFromMe'] ?? false;

        if (! $targetExternalId) {
            Log::warning('WhatsappApiwayHandler: reaction without a target', [
                'connection_id' => $connection->id,
                'message_id' => $info['ID'] ?? null,
            ]);

            return;
        }

        $target = Message::whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
            ->where('external_id', $targetExternalId)
            ->first();

        if (! $target) {
            // Reacting to a message older than this connection's history.
            Log::info('WhatsappApiwayHandler: reaction target not found', [
                'connection_id' => $connection->id,
                'target_external_id' => $targetExternalId,
            ]);

            return;
        }

        $senderType = $isFromMe ? SenderType::Outgoing : SenderType::Incoming;
        $reactor = null;

        // In a group the reactor has to be part of the key: several members
        // react to the same message, and keying on sender_type alone would
        // make each new reaction overwrite the previous member's.
        if (! $isFromMe && $target->conversation->isGroup()) {
            $senderJids = [$info['SenderAlt'] ?? null, $info['Sender'] ?? null];
            $reactorPhone = $this->resolvePhoneFromJids($connection, $senderJids);

            if (! $reactorPhone) {
                Log::warning('WhatsappApiwayHandler: group reaction without an identifiable sender', [
                    'target_external_id' => $targetExternalId,
                    'lid' => $this->lidFromJids($senderJids),
                ]);

                return;
            }

            $reactor = Contact::createFromExternalData(
                $connection,
                $reactorPhone,
                ($info['PushName'] ?? null) ?: $reactorPhone,
                $reactorPhone,
            );
            $this->rememberLid($reactor, $this->lidFromJids($senderJids));
        }

        $key = [
            'message_id' => $target->id,
            'sender_type' => $senderType,
            'contact_id' => $reactor?->id,
        ];

        if ($emoji === '' || $emoji === null) {
            MessageReaction::where($key)->delete();
        } else {
            MessageReaction::updateOrCreate($key, ['emoji' => $emoji]);
        }

        broadcast(new MessageUpdated($target->fresh()->load('reactions.contact')));
    }

    /**
     * GroupInfo (subject changed) / JoinedGroup (we entered a group) events —
     * the only places whatsmeow surfaces the group subject. Shapes differ:
     * GroupInfo nests the new subject under Name.Name; JoinedGroup embeds
     * types.GroupName so Name is a flat string.
     */
    private function handleGroupMetadata(Connection $connection, array $event): void
    {
        $groupJid = $event['JID'] ?? null;

        $name = $event['Name'] ?? null;
        $title = is_array($name) ? ($name['Name'] ?? null) : (is_string($name) ? $name : null);

        if (! $groupJid || ! $title) {
            return;
        }

        $conversation = GroupConversationService::rename($connection, $groupJid, $title);

        if ($conversation) {
            broadcast(new ConversationUpdated($conversation->load('contact')));
        }
    }

    /**
     * whatsmeow announces avatar changes with a Picture event carrying the
     * subject's JID and a Remove flag — for a person, a group, or our own
     * number. The new image itself is not included, so a change means re-read
     * it through the resolver; a removal is applied straight away.
     *
     * Only contacts we already know are touched: a Picture event is no reason
     * to materialise a contact we have never exchanged a message with.
     */
    private function handlePictureChange(Connection $connection, array $event): void
    {
        $jid = $event['JID'] ?? null;

        if (! $jid) {
            return;
        }

        if (str_ends_with($jid, '@g.us')) {
            $externalId = $jid;
        } else {
            $externalId = $this->resolvePhoneFromJids($connection, [$jid]);
        }

        if (! $externalId) {
            return;
        }

        $contact = Contact::where('tenant_id', $connection->tenant_id)
            ->where('external_id', $externalId)
            ->first();

        if (! $contact) {
            return;
        }

        if ($event['Remove'] ?? false) {
            app(ContactPhotoSyncer::class)->clear($contact);

            return;
        }

        SyncContactPhoto::dispatchForced($contact, $connection);
    }

    /**
     * Status posts (`status@broadcast`) and broadcast lists (`<id>@broadcast`)
     * both live on the `@broadcast` server.
     */
    private function isBroadcastChat(?string $chatJid): bool
    {
        return $chatJid !== null && str_ends_with($chatJid, '@broadcast');
    }

    /**
     * True when the Message node holds only the keys in self::ENVELOPE_KEYS —
     * no conversation, media, location or any other renderable node.
     */
    private function isEnvelopeOnly(array $message): bool
    {
        $present = array_keys(array_filter($message, fn ($node) => $node !== null));

        return array_diff($present, self::ENVELOPE_KEYS) === [];
    }

    /**
     * Placeholder name for a group we only know by JID:
     * "555491607349-1623173607@g.us" → "555491607349-1623173607".
     */
    private function groupFallbackTitle(string $groupJid): string
    {
        return explode('@', $groupJid)[0];
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
            Log::info('WhatsappApiwayHandler: receipt ignored', ['type' => $type, 'ids' => $ids]);

            return;
        }

        // Same offset trap as message timestamps — receipts feed read_at/delivery_at.
        $timestamp = $this->parseTimestamp($event['Timestamp'] ?? null);

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
        return $this->parseTimestamp($event['Info']['Timestamp'] ?? null);
    }

    /**
     * whatsmeow emits RFC3339 carrying the host's UTC offset (e.g.
     * `2026-07-21T10:34:28-03:00`). Carbon keeps that offset, and Eloquent then
     * writes the *wall clock* — so `10:34:28` landed in a UTC column while a
     * message sent from the panel at the same instant stored `13:34:28` via
     * now(). Same conversation, timestamps 3 hours apart, order scrambled.
     *
     * Normalising to the app timezone puts every source on one clock.
     */
    private function parseTimestamp(?string $timestamp): Carbon
    {
        if (! $timestamp) {
            return Carbon::now();
        }

        try {
            return Carbon::parse($timestamp)->setTimezone(config('app.timezone'));
        } catch (\Throwable $exception) {
            Log::warning('WhatsappApiwayHandler: unparseable timestamp, falling back to now()', [
                'timestamp' => $timestamp,
                'error' => $exception->getMessage(),
            ]);

            return Carbon::now();
        }
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
        return $this->phoneFromJids($this->partnerJids($event));
    }

    /**
     * The partner's `@lid`, normalised to `<user>@lid` (device id stripped) so
     * it matches how `contacts.lid` stores the alias.
     */
    public function getContactLid(array $event): ?string
    {
        return $this->lidFromJids($this->partnerJids($event));
    }

    /**
     * The conversation partner's JIDs, best identity first. Direction matters:
     * incoming (IsFromMe=false) → the sender, phone in SenderAlt; outgoing
     * (IsFromMe=true) → the recipient, phone in RecipientAlt.
     */
    private function partnerJids(array $event): array
    {
        $info = $event['Info'] ?? [];

        if ($info['IsFromMe'] ?? false) {
            return [$info['RecipientAlt'] ?? null, $info['Chat'] ?? null];
        }

        return [$info['SenderAlt'] ?? null, $info['Sender'] ?? null, $info['Chat'] ?? null];
    }

    /**
     * The first candidate that is a real phone JID. A bare `@lid` is skipped:
     * it is an opaque handle, not a number, so it can neither key an identity
     * nor be used as a send target.
     */
    private function phoneFromJids(array $jids): ?string
    {
        foreach ($jids as $jid) {
            if (! $this->isLidJid($jid) && ($phone = $this->extractPhone($jid)) !== null) {
                return $phone;
            }
        }

        return null;
    }

    private function lidFromJids(array $jids): ?string
    {
        foreach ($jids as $jid) {
            if ($this->isLidJid($jid) && ($user = $this->extractPhone($jid)) !== null) {
                return $user.'@lid';
            }
        }

        return null;
    }

    /**
     * The partner's phone number — the only identity a conversation may be
     * keyed by, since it doubles as the send target.
     *
     * whatsmeow re-delivers a message it first failed to decrypt (the event
     * carries `UnavailableRequestID`) with a STRIPPED Info block: no
     * SenderAlt, no PushName, no Type — only the `@lid`. Taking that `@lid` as
     * an identity forked the customer into a second contact and a second,
     * unrepliable conversation, minutes after the good copy had already
     * landed (prod 19445/19446, 19525/19526, 19560/19562). So a `@lid`-only
     * event is resolved through the alias recorded when both were visible.
     */
    private function resolvePhoneFromJids(Connection $connection, array $jids): ?string
    {
        if (($phone = $this->phoneFromJids($jids)) !== null) {
            return $phone;
        }

        $lid = $this->lidFromJids($jids);

        if ($lid === null) {
            return null;
        }

        return Contact::where('tenant_id', $connection->tenant_id)
            ->where('lid', $lid)
            ->value('external_id');
    }

    /**
     * Record the `@lid` alias the first time an event shows both identities,
     * so a later `@lid`-only re-delivery still resolves to this contact.
     */
    private function rememberLid(Contact $contact, ?string $lid): void
    {
        if ($lid !== null && $contact->lid !== $lid) {
            $contact->update(['lid' => $lid]);
        }
    }

    private function isLidJid(?string $jid): bool
    {
        return $jid !== null && str_ends_with($jid, '@lid');
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

    /**
     * Has this message already been stored on this connection? whatsmeow can
     * re-deliver one message minutes later, and the copies need not resolve to
     * the same conversation — so this check cannot be conversation-scoped.
     */
    private function alreadyStored(Connection $connection, string $messageId): bool
    {
        return Message::whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
            ->where('external_id', $messageId)
            ->exists();
    }

    // --- Media -------------------------------------------------------------

    /**
     * Download the encrypted WhatsApp media from the CDN URL in the webhook and
     * decrypt it locally (HKDF-SHA256 + AES-256-CBC) — the standard WhatsApp
     * media scheme. Everything needed is in the payload, so this doesn't depend
     * on API Way's (undocumented) download-media response shape.
     */
    /**
     * Queue-side entry point: the whatsmeow event was stored on the message,
     * and it carries the CDN URL plus the media key — everything the decrypt
     * needs, with no second call to API Way.
     */
    public function downloadMedia(Message $message): void
    {
        $this->handleMediaMessage($message, $message->meta ?? [], $message->message_type);
    }

    private function handleMediaMessage(Message $message, array $event, MessageType $type): void
    {
        $node = $this->getMediaNode($event, $type);
        $url = $node['URL'] ?? null;
        $mediaKeyB64 = $node['mediaKey'] ?? null;
        $mimetype = $node['mimetype'] ?? null;

        if (! $node || ! $url || ! $mediaKeyB64 || ! $mimetype) {
            Log::warning('WhatsappApiwayHandler: missing media data', ['message_id' => $message->id]);
            $message->update(['error' => 'Missing media data']);

            return;
        }

        try {
            $enc = Http::timeout(120)->get($url);
            if ($enc->failed()) {
                $message->update(['error' => 'Failed to download media']);

                return;
            }

            $plain = $this->decryptWhatsappMedia($enc->body(), base64_decode($mediaKeyB64), $type);
            if ($plain === null) {
                $message->update(['error' => 'Failed to decrypt media']);

                return;
            }

            $path = 'media/'.$message->id.'_'.uniqid().'.'.$this->extensionFromMime($mimetype);
            Storage::disk('local')->put($path, $plain);
            $message->update(['attachment' => $path]);

            Log::info('WhatsappApiwayHandler: media downloaded', ['message_id' => $message->id, 'path' => $path]);
        } catch (\Throwable $e) {
            Log::error('WhatsappApiwayHandler: media handling failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            $message->update(['error' => $e->getMessage()]);
        }
    }

    private function getMediaNode(array $event, MessageType $type): ?array
    {
        $m = $event['Message'] ?? [];

        return match ($type) {
            MessageType::Image => $m['imageMessage'] ?? null,
            MessageType::Video => $m['videoMessage'] ?? null,
            MessageType::Audio => $m['audioMessage'] ?? null,
            MessageType::Sticker => $m['stickerMessage'] ?? null,
            MessageType::Document => $m['documentMessage']
                ?? $m['documentWithCaptionMessage']['message']['documentMessage']
                ?? null,
            default => null,
        };
    }

    /**
     * @return string|null decrypted bytes, or null on failure
     */
    private function decryptWhatsappMedia(string $encrypted, string $mediaKey, MessageType $type): ?string
    {
        $info = match ($type) {
            MessageType::Image, MessageType::Sticker => 'WhatsApp Image Keys',
            MessageType::Video => 'WhatsApp Video Keys',
            MessageType::Audio => 'WhatsApp Audio Keys',
            MessageType::Document => 'WhatsApp Document Keys',
            default => null,
        };

        if ($info === null || strlen($mediaKey) === 0 || strlen($encrypted) <= 10) {
            return null;
        }

        // HKDF-SHA256 expand to 112 bytes: iv(16) + cipherKey(32) + macKey(32) + ref(32).
        $expanded = hash_hkdf('sha256', $mediaKey, 112, $info);
        $iv = substr($expanded, 0, 16);
        $cipherKey = substr($expanded, 16, 32);

        // The file is ciphertext + 10-byte truncated HMAC.
        $ciphertext = substr($encrypted, 0, -10);

        $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? null : $plain;
    }

    private function extensionFromMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/amr' => 'amr',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        ];

        return $map[explode(';', $mime)[0]] ?? 'bin';
    }
}
