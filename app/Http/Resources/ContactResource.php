<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'name' => $this->name,
            'is_group' => (bool) $this->is_group,
            'username' => $this->username,
            'photo_profile_url' => $this->photo_profile_url,
            // The address a campaign would reach them at, and whether they have
            // asked not to be reached. Both are what the recipient picker needs
            // to show a contact honestly.
            'external_id' => $this->external_id,
            'broadcast_opted_out' => $this->broadcast_opted_out_at !== null,
            // Always present, never `whenLoaded`. This resource is nested in
            // every conversation the panel renders and in ~15 broadcast paths
            // that build a ConversationResource from a freshly loaded model,
            // and the whole promise of a contact tag is that it shows on *any*
            // thread. A key that vanished because one of those paths forgot an
            // eager load would look exactly like a tag that was never applied —
            // and the client, which replaces its cached row from the broadcast,
            // would then forget the tags it already had.
            //
            // `loadMissing`, so the list paths that do eager-load pay nothing
            // and the one-conversation paths pay one query. See
            // ConversationController@index and @messages for the loads that
            // keep this off the hot paths.
            'tags' => TagResource::collection($this->resource->loadMissing('tags')->tags),
        ];
    }
}
