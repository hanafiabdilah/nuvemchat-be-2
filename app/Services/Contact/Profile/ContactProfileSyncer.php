<?php

namespace App\Services\Contact\Profile;

use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a channel id into a name a human recognises, and keeps trying until it
 * can.
 *
 * The old inline version ran once, gated on wasRecentlyCreated. On Instagram
 * that gate lands on the one moment the lookup is guaranteed to fail: when the
 * business writes first (or its echo webhook opens the thread), the person has
 * not messaged the account yet, so the User Profile API refuses and the
 * contact is stored under its numeric IGSID. The reply that would make the
 * lookup work arrives on an already-created contact, and nothing ever asked
 * again — which is exactly the "name still shows as a number" report.
 */
class ContactProfileSyncer
{
    /**
     * How long to wait before asking again after a lookup that did not produce
     * an identity. Without it a connection whose token lacks the messaging
     * permission would fire one doomed request per inbound message.
     */
    public const RETRY_HOURS = 1;

    /**
     * True when this contact is worth a (queued) lookup right now.
     *
     * Resolution is one-way: once a username is stored the contact is named
     * for good and no further calls are made. A username is the right signal
     * because Instagram always returns one on success, so its absence is the
     * only reliable "never resolved" marker — the name alone is not, since a
     * placeholder id and a legitimately numeric display name look identical.
     */
    public static function needsSync(Contact $contact): bool
    {
        if (! $contact->channel || ! ProfileResolverFactory::supports($contact->channel)) {
            return false;
        }

        if (filled($contact->username)) {
            return false;
        }

        return $contact->profile_synced_at === null
            || $contact->profile_synced_at->lt(now()->subHours(self::RETRY_HOURS));
    }

    /**
     * Re-read the identity and store it. Returns true when the contact row
     * actually moved.
     */
    public function sync(Contact $contact, Connection $connection): bool
    {
        $resolver = ProfileResolverFactory::for($contact->channel);

        if (! $resolver) {
            return false;
        }

        try {
            $profile = $resolver->resolve($contact, $connection);
        } catch (Throwable $e) {
            // Transient, or a permission that may still be granted: keep the
            // placeholder and stamp the attempt so the retry is paced.
            Log::warning('ContactProfileSyncer: lookup failed', [
                'contact_id' => $contact->id,
                'channel' => $contact->channel->value,
                'error' => $e->getMessage(),
            ]);

            $contact->forceFill(['profile_synced_at' => now()])->save();

            return false;
        }

        $updates = [];

        // A name typed by an agent outranks the channel — same rule as
        // Contact::createFromExternalData.
        if (! $contact->name_locked && filled($profile?->name) && $contact->name !== $profile->name) {
            $updates['name'] = $profile->name;
        }

        if (filled($profile?->username) && $contact->username !== $profile->username) {
            $updates['username'] = $profile->username;
        }

        $contact->forceFill($updates + ['profile_synced_at' => now()])->save();

        if (! $updates) {
            return false;
        }

        Log::info('ContactProfileSyncer: contact identity updated', [
            'contact_id' => $contact->id,
            'channel' => $contact->channel->value,
            'name' => $contact->name,
            'username' => $contact->username,
        ]);

        $this->broadcastToOpenConversations($contact);

        return true;
    }

    /**
     * The inbox renders the contact name off the conversation row it already
     * has in IndexedDB, so a renamed contact only reaches an open dashboard
     * through conversation-updated.
     */
    private function broadcastToOpenConversations(Contact $contact): void
    {
        $conversations = Conversation::with(['contact', 'connection'])
            ->where('contact_id', $contact->id)
            ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
            ->get();

        foreach ($conversations as $conversation) {
            broadcast(new ConversationUpdated($conversation));
        }
    }
}
