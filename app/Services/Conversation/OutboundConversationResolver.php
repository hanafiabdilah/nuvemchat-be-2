<?php

namespace App\Services\Conversation;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * Find-or-create the thread a business-initiated message belongs in.
 *
 * Three paths used to answer this question separately — the template sender,
 * the e-mail composer and now the broadcast engine — and they disagreed in ways
 * that only show up in production: which statuses count as "still open",
 * whether a second send forks the thread, and what `external_id` a brand-new
 * conversation gets. That last one is the subtle part, because it is not the
 * contact's id on every channel:
 *
 *   WhatsApp — the phone number, which is exactly the contact's own id.
 *   E-mail   — a per-subject thread key, so replies to two different subjects
 *              do not collapse into one conversation. Mirrors the key
 *              EmailInboxSynchronizer threads inbound mail by.
 *   Telegram / Discord / Instagram / Messenger — an id the platform minted when
 *              the customer first wrote (a DM channel, a scoped id). It cannot
 *              be derived from the contact at all, so the last thread's id is
 *              reused and, failing that, the business simply cannot open a
 *              conversation here yet.
 *
 * Deliberately not used by ConversationController::store: that endpoint refuses
 * with 409 when an open thread already exists instead of reusing it, which is a
 * different contract, not a variation on this one.
 */
class OutboundConversationResolver
{
    /**
     * Statuses a thread may be continued from. Resolved is left out on purpose:
     * it was closed, and reopening it silently would hide the new message under
     * an old, already-handled conversation.
     */
    public const OPEN_STATUSES = [Status::Active, Status::Pending, Status::AiHandling];

    /**
     * @param  int|null  $assignedUserId  Agent the new thread belongs to. Null keeps
     *                                    it in the unassigned queue (the e-mail inbox
     *                                    is shared and always passes null).
     * @param  string|null  $emailSubject  Required on the e-mail channel; ignored elsewhere.
     * @param  bool  $activateOnReuse  Promote a reused thread to Active. Callers that
     *                                 must not interrupt a running AI turn pass false.
     * @return ResolvedConversation|null  Null when the channel offers no way to address
     *                                    this contact yet (see the class docblock).
     */
    public function resolve(
        Connection $connection,
        Contact $contact,
        ?int $assignedUserId = null,
        ?string $emailSubject = null,
        bool $activateOnReuse = true,
    ): ?ResolvedConversation {
        $externalId = $this->externalIdFor($connection, $contact, $emailSubject);

        if ($externalId === null || $externalId === '') {
            return null;
        }

        $query = Conversation::where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', self::OPEN_STATUSES);

        // E-mail threads are keyed per subject, so an open thread only counts as
        // the same conversation when its key matches; every other channel has
        // one thread per contact and the key is redundant.
        if ($connection->channel === Channel::Email) {
            $query->where('external_id', $externalId);
        }

        $conversation = $query->latest('id')->first();

        if ($conversation) {
            $updates = ['last_message_at' => now()];

            if ($activateOnReuse) {
                $updates['status'] = Status::Active;
            }

            $conversation->update($updates);

            return new ResolvedConversation($conversation, false);
        }

        return new ResolvedConversation(
            Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $externalId,
                'status' => Status::Active,
                'user_id' => $assignedUserId,
                // Placeholder ordering so the thread surfaces immediately; the
                // Message::created hook replaces it with the real timestamp.
                'last_message_at' => now(),
            ]),
            true,
        );
    }

    /**
     * The id the channel actually delivers to — see the class docblock for why
     * this differs so much per channel.
     */
    private function externalIdFor(Connection $connection, Contact $contact, ?string $emailSubject): ?string
    {
        return match ($connection->channel) {
            Channel::Email => self::emailThreadKey($contact, $emailSubject ?? ''),
            Channel::WhatsappOfficial, Channel::WhatsappApiway => $contact->external_id,
            default => Conversation::where('connection_id', $connection->id)
                ->where('contact_id', $contact->id)
                ->latest('id')
                ->value('external_id'),
        };
    }

    /**
     * Thread key for an e-mail conversation. Mirrors
     * EmailInboxSynchronizer::conversationExternalId so a reply that comes back
     * on the same subject lands in the thread we opened rather than a new one.
     */
    public static function emailThreadKey(Contact $contact, ?string $subject): string
    {
        return 'email:' . sha1($contact->id . '|' . self::normalizeEmailSubject($subject));
    }

    /** Strip any number of Re:/Fw:/Fwd: prefixes, then squish and lowercase. */
    public static function normalizeEmailSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        do {
            $previous = $subject;
            $subject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $previous);

        return Str::of($subject)->squish()->lower()->toString();
    }
}
