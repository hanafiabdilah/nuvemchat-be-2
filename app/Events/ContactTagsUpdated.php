<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Http\Resources\TagResource;
use App\Models\Contact;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The tags on a person changed, so every thread they own now shows something
 * different.
 *
 * On the tenant channel rather than the per-connection one that carries
 * conversation content, for the same reason LeadUpdated is: a contact is not
 * scoped to a connection — the same person can have threads on several — so
 * there is no single connection channel this belongs on. What keeps it
 * defensible is the payload: an id and a set of tag rows the whole workspace
 * already reads from GET /tags. No name, no address, no message.
 *
 * Delivery to clients that were offline is not this event's job — see
 * ContactTags::propagate(), which also bumps the conversations so the ordinary
 * delta sync carries the change.
 */
class ContactTagsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Contact $contact) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            Channels::tenant($this->contact->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'contact-tags-updated';
    }

    public function broadcastWith(): array
    {
        $contact = $this->contact->loadMissing('tags');

        return [
            'contact_id' => (string) $contact->id,
            'tags' => TagResource::collection($contact->tags)->resolve(),
        ];
    }
}
