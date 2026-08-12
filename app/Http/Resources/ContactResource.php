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
        ];
    }
}
