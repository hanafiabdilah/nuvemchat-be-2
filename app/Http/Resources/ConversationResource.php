<?php

namespace App\Http\Resources;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
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
            'last_message_at' => $this->last_message_at->timestamp,
            'unread' => $this->messages()->where('sender_type', SenderType::Incoming)->whereNull('read_at')->count(),
            'contact' => $this->whenLoaded('contact', new ContactResource($this->contact)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
