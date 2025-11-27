<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MessageResource extends JsonResource
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
            'sender_type' => $this->sender_type,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'attachment_url' => $this->attachment ? Storage::disk('local')->temporaryUrl($this->attachment, Carbon::now()->addMinutes(5)) : null,
            'sent_at' => $this->sent_at,
        ];
    }
}
