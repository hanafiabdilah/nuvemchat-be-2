<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
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
            'connection_id' => $this->connection_id,
            'last_message' => $this->last_message->toResource(MessageResource::class),
            'last_message_at' => $this->last_message_at,
            'unread' => $this->messages()->whereNull('read_at')->count(),
            'contact' => new ContactResource($this->whenLoaded('contact')),
            'created_at' => $this->created_at,
        ];
    }
}
