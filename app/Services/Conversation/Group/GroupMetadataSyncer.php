<?php

namespace App\Services\Conversation\Group;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Conversation\GroupConversationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a group contact's name in step with the channel.
 *
 * The push side (GroupInfo / JoinedGroup webhooks) only fires on a rename or a
 * join, so it can never name a group that already existed when the instance was
 * linked. This is the pull side: read the subject from the core, on a TTL,
 * driven off the ingest path by SyncGroupMetadata.
 *
 * Only API Way needs it — Telegram sends the authoritative title on every
 * message, and WhatsApp Official has no group support yet.
 */
class GroupMetadataSyncer
{
    /** How long a fetched subject is trusted before it is read again. */
    public const TTL_HOURS = 24;

    /**
     * Back-off after a failed read, so a connection with a dead token does not
     * fire one doomed request per inbound group message.
     */
    public const RETRY_HOURS = 1;

    public function __construct(
        private ApiwayGroupClient $client,
    ) {}

    /** Channels whose group subject can be pulled on demand. */
    public static function supports(?Channel $channel): bool
    {
        return $channel === Channel::WhatsappApiway;
    }

    /**
     * True when this group is worth a (queued) lookup right now. Checked before
     * dispatch so a busy group does not pile identical jobs on the queue.
     */
    public static function isStale(Contact $contact): bool
    {
        if (! $contact->is_group || ! self::supports($contact->channel)) {
            return false;
        }

        // A locked name is the agent's own; never spend a request to re-read
        // something we are not allowed to apply.
        if ($contact->name_locked) {
            return false;
        }

        return $contact->group_synced_at === null
            || $contact->group_synced_at->lt(now()->subHours(self::TTL_HOURS));
    }

    /**
     * Re-read one group's subject and apply it. Returns true when the stored
     * name actually changed.
     */
    public function sync(Contact $contact, Connection $connection): bool
    {
        if (! self::supports($contact->channel)) {
            return false;
        }

        try {
            $name = $this->client->name($connection, $contact->external_id);
        } catch (Throwable $e) {
            Log::warning('GroupMetadataSyncer: lookup failed', [
                'contact_id' => $contact->id,
                'group_jid' => $contact->external_id,
                'error' => $e->getMessage(),
            ]);

            $this->backOff($contact);

            return false;
        }

        // A 200 with no name means the core knows the group but has no subject
        // for it — nothing to apply, and no reason to keep asking today.
        $changed = $name !== null && $this->apply($connection, $contact, $name);

        $contact->forceFill(['group_synced_at' => now()])->save();

        return $changed;
    }

    /**
     * Store a subject learned for a group JID and let open threads re-render.
     * Goes through GroupConversationService so a manually renamed group
     * (name_locked) is left alone, exactly as a GroupInfo webhook would be.
     */
    public function apply(Connection $connection, Contact $contact, string $name): bool
    {
        if ($contact->name === $name || $contact->name_locked) {
            return false;
        }

        GroupConversationService::resolveGroupContact($connection, $contact->external_id, $name);

        if ($contact->fresh()->name !== $name) {
            return false;
        }

        $conversation = Conversation::where('connection_id', $connection->id)
            ->where('external_id', $contact->external_id)
            ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
            ->first();

        if ($conversation) {
            broadcast(new ConversationUpdated($conversation->load('contact')));
        }

        Log::info('GroupMetadataSyncer: group renamed from channel metadata', [
            'contact_id' => $contact->id,
            'group_jid' => $contact->external_id,
            'name' => $name,
        ]);

        return true;
    }

    /** Push the next attempt out by RETRY_HOURS rather than the full TTL. */
    private function backOff(Contact $contact): void
    {
        $contact->forceFill([
            'group_synced_at' => now()->subHours(self::TTL_HOURS - self::RETRY_HOURS),
        ])->save();
    }
}
