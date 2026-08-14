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
            'type' => $this->type?->value ?? 'private',
            'status' => $this->status->value,
            'needs_human' => (bool) $this->needs_human,
            'handoff_reason' => $this->handoff_reason,
            'handoff_at' => $this->handoff_at?->timestamp,
            // Muted threads keep syncing and keep their unread badge; they just
            // raise no toast and play no sound.
            'muted' => $this->muted_at !== null,
            'last_message' => $message,
            'last_message_at' => $this->last_message_at?->timestamp,
            // Prefer the withCount aggregate when the query provided it (sync
            // pages) — the fallback query runs once per conversation otherwise.
            'unread' => $this->unread_count ?? $this->messages()->where('sender_type', SenderType::Incoming)->whereNull('read_at')->count(),
            // Same deal: counted by the sync page's query, counted per row on
            // the single-conversation paths (a broadcast, an accept). Always a
            // number — a key that disappeared on a broadcast would take the
            // list's note marker with it until the next full sync.
            'notes_count' => $this->resource->notes_count ?? $this->notes()->count(),
            'contact' => ContactResource::make($this->contact),
            'participants' => ContactResource::collection($this->whenLoaded('participants')),
            'tags' => TagResource::collection($this->tags),
            'agent' => UserResource::make($this->agent),
            'flow_state' => $this->flowState ? new FlowStateResource($this->flowState) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
