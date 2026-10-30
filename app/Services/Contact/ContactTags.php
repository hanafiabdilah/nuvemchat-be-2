<?php

namespace App\Services\Contact;

use App\Events\ContactTagsUpdated;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;

/**
 * Putting tags on a person, and making every screen that already shows that
 * person notice.
 *
 * Four callers write contact tags — the customer page, the panel beside a
 * thread, the flow builder's Tagging node and a customer merge — and all of
 * them need the same things to happen afterwards, which is the whole reason
 * this is a class and not copies of `$contact->tags()->sync()`.
 */
class ContactTags
{
    /**
     * Replace the contact's tags with exactly this set.
     *
     * Ids that do not belong to the tenant are dropped rather than rejected:
     * the same shape ConversationController@syncTags already uses, and the
     * caller cannot construct one by accident from a UI that only lists the
     * workspace's own tags.
     *
     * @param  array<int, mixed>  $tagIds
     */
    public function sync(Contact $contact, array $tagIds): Collection
    {
        $owned = $this->ownedByTenant($contact, $tagIds);

        foreach ($this->samePerson($contact) as $each) {
            $each->tags()->sync($owned);
            $this->propagate($each);
        }

        return $contact->tags;
    }

    /**
     * Add tags without touching the ones already there.
     *
     * @param  array<int, mixed>  $tagIds
     */
    public function add(Contact $contact, array $tagIds): Collection
    {
        $owned = $this->ownedByTenant($contact, $tagIds);

        foreach ($this->samePerson($contact) as $each) {
            $each->tags()->syncWithoutDetaching($owned);
            $this->propagate($each);
        }

        return $contact->tags;
    }

    /**
     * @param  array<int, mixed>  $tagIds
     */
    public function remove(Contact $contact, array $tagIds): Collection
    {
        $owned = $this->ownedByTenant($contact, $tagIds);

        foreach ($this->samePerson($contact) as $each) {
            $each->tags()->detach($owned);
            $this->propagate($each);
        }

        return $contact->tags;
    }

    /**
     * This contact first, then the person's other channel contacts.
     *
     * A contact tag describes the person (CRM: one customer above the channel
     * contacts), so it is written to every identity they write from — tagging
     * someone "VIP" on WhatsApp must show on their Instagram thread too, and
     * the customer page reads the tags off any of them.
     *
     * @return list<Contact>
     */
    private function samePerson(Contact $contact): array
    {
        if (! $contact->customer_id) {
            return [$contact];
        }

        $siblings = Contact::query()
            ->where('customer_id', $contact->customer_id)
            ->where('tenant_id', $contact->tenant_id)
            ->whereKeyNot($contact->id)
            ->get()
            ->all();

        return [$contact, ...$siblings];
    }

    /**
     * Make the change reach every client, online or not.
     *
     * Two mechanisms, and both are needed for the same reason the group-removal
     * feature needs two:
     *
     *  - The broadcast reaches whoever is connected right now, within the
     *    second. That is the common case and the one people notice.
     *
     *  - Bumping the conversations is what reaches the tab that was closed.
     *    The client syncs conversations by `updated_at`, and a contact tag
     *    changes no row in `conversations` at all — so without this, an agent
     *    who was offline would keep the old tags in IndexedDB indefinitely: a
     *    delta only reports rows touched after the cursor, and reloading does
     *    not reset that cursor.
     *
     * A mass update, which fires no model events — deliberately. Loading and
     * saving every thread this person ever opened would run
     * ConversationObserver on each one, and a bumped timestamp is not a status
     * change: there is nothing there for it to observe.
     */
    private function propagate(Contact $contact): Collection
    {
        // `load`, not `loadMissing`: sync() does not refresh a relation that
        // was already read, and the stale copy is what would be broadcast.
        $contact->load('tags');

        $contact->conversations()->update(['updated_at' => now()]);

        broadcast(new ContactTagsUpdated($contact));

        return $contact->tags;
    }

    /**
     * @param  array<int, mixed>  $tagIds
     * @return array<int, int>
     */
    private function ownedByTenant(Contact $contact, array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        return Tag::where('tenant_id', $contact->tenant_id)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();
    }
}
