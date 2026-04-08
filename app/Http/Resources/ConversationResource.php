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
        $message = new MessageResource($this->last_message);
        $message->withoutAttachmentUrl = true;

        return [
            'id' => $this->id,
            'connection_id' => $this->connection_id,
            'status' => $this->status->value,
            'last_message' => $message,
            'last_message_at' => $this->last_message_at->timestamp,
            'unread' => $this->messages()->where('sender_type', SenderType::Incoming)->whereNull('read_at')->count(),
            'contact' => ContactResource::make($this->contact),
            'tags' => TagResource::collection($this->tags),
            'agent' => UserResource::make($this->agent),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
