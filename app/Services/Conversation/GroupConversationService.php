<?php

namespace App\Services\Conversation;

use App\Enums\Conversation\Status;
use App\Enums\Conversation\Type;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Channel-agnostic helpers for group conversations.
 *
 * The model every channel should follow when ingesting group chats:
 * 1. The group itself is a Contact row (is_group = true, external_id = the
 *    channel's group/chat id, name = the group title). The conversation keeps
 *    pointing at it so list/header/avatar rendering works unchanged.
 * 2. The conversation row carries type = Type::Group and external_id = group id.
 * 3. Each incoming message stores its real sender in messages.contact_id and
 *    the sender is registered in conversation_participants — the conversation
 *    is never tied to a single human contact.
 * 4. Automation flows / AI never run in group conversations (guarded in
 *    FlowExecutor); agents reply manually.
 */
class GroupConversationService
{
    /**
     * Run $callback while holding an exclusive lock on one chat.
     *
     * Ingesting a group message is find-or-create: read, miss, insert. Channels
     * deliver bursts for the same group in parallel — whatsmeow alone emits two
     * events per message (sender-key distribution, then content) — and each
     * webhook is its own PHP request, so two of them can miss the same SELECT
     * and insert a conversation apiece, splitting the thread in two.
     *
     * The lock must wrap the whole transaction, not sit inside it: a lock
     * released before COMMIT still lets the next reader miss the uncommitted
     * row. Best-effort — if the holder is slow we run unserialised rather than
     * drop an inbound message.
     */
    public static function lockChat(Connection $connection, string $externalId, Closure $callback)
    {
        $lock = Cache::lock("group-conversation:{$connection->id}:{$externalId}", 15);

        try {
            return $lock->block(10, $callback);
        } catch (LockTimeoutException) {
            Log::warning('GroupConversationService: chat lock timed out, proceeding unserialised', [
                'connection_id' => $connection->id,
                'external_id' => $externalId,
            ]);

            return $callback();
        }
    }

    /**
     * Find-or-create the contact row that represents the group itself.
     * Renames follow the group title unless an admin locked the name.
     *
     * $renameIfChanged distinguishes how much the caller trusts $title:
     * Telegram sends the authoritative title on every message (true), while
     * WhatsApp message events carry no subject — callers pass a derived
     * fallback that must only be used at creation, never to overwrite a real
     * name learned later (false).
     */
    public static function resolveGroupContact(Connection $connection, string $externalId, ?string $title, bool $renameIfChanged = true): Contact
    {
        $contact = Contact::firstOrCreate([
            'external_id' => $externalId,
            'tenant_id' => $connection->tenant_id,
        ], [
            'name' => $title !== null && $title !== '' ? $title : $externalId,
            'channel' => $connection->channel,
            'is_group' => true,
        ]);

        if (!$contact->wasRecentlyCreated) {
            $updates = [];

            if (!$contact->is_group) {
                $updates['is_group'] = true;
            }

            if ($renameIfChanged && $title && !$contact->name_locked && $contact->name !== $title) {
                $updates['name'] = $title;
            }

            if (!empty($updates)) {
                $contact->update($updates);
            }
        }

        return $contact;
    }

    /**
     * Find the open group conversation for this chat or create one. Mirrors the
     * private-chat behaviour: a Resolved conversation stays closed and a new
     * message opens a fresh Pending conversation.
     */
    public static function resolveConversation(Connection $connection, string $externalId, ?string $title, bool &$isNew = false, bool $renameIfChanged = true): Conversation
    {
        $contact = self::resolveGroupContact($connection, $externalId, $title, $renameIfChanged);

        $conversation = Conversation::where('connection_id', $connection->id)
            ->where('external_id', $externalId)
            ->where('type', Type::Group)
            ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $externalId,
                'type' => Type::Group,
                'status' => Status::Pending,
            ]);
            $isNew = true;
        }

        return $conversation;
    }

    /**
     * Register a contact as a participant of the conversation (idempotent).
     * The group contact itself is never a participant.
     */
    public static function addParticipant(Conversation $conversation, Contact $contact): void
    {
        if ($contact->is_group) {
            return;
        }

        $conversation->participants()->syncWithoutDetaching([$contact->id]);
    }

    /**
     * Apply a group title change. Returns the open conversation (so the caller
     * can broadcast ConversationUpdated) or null when none is open.
     */
    public static function rename(Connection $connection, string $externalId, string $title): ?Conversation
    {
        self::resolveGroupContact($connection, $externalId, $title);

        return Conversation::where('connection_id', $connection->id)
            ->where('external_id', $externalId)
            ->where('type', Type::Group)
            ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
            ->first();
    }

    /**
     * The channel changed the group's id (e.g. a Telegram group upgraded to a
     * supergroup). Repoint the group contact and every group conversation.
     */
    public static function migrateExternalId(Connection $connection, string $oldExternalId, string $newExternalId): void
    {
        Contact::where('tenant_id', $connection->tenant_id)
            ->where('external_id', $oldExternalId)
            ->where('is_group', true)
            ->update(['external_id' => $newExternalId]);

        Conversation::where('connection_id', $connection->id)
            ->where('external_id', $oldExternalId)
            ->where('type', Type::Group)
            ->update(['external_id' => $newExternalId]);
    }
}
