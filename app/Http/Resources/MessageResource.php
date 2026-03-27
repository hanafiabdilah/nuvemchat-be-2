<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MessageResource extends JsonResource
{
    public bool $withoutAttachmentUrl = false;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_type' => $this->sender_type,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'attachment_url' => $this->when(!$this->withoutAttachmentUrl, fn() =>
                $this->attachment ? Storage::disk('local')->temporaryUrl($this->attachment, Carbon::now()->addMonths(6)) : null
            ),
            'sent_at' => $this->sent_at,
            'delivery_at' => $this->delivery_at,
            'read_at' => $this->read_at,
            'edited_at' => $this->edited_at,
            'unsend_at' => $this->unsend_at,
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }
}
