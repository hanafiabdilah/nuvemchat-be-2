<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BroadcastRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'address' => $this->address,
            'name' => $this->displayName(),
            'status' => $this->status->value,
            // The platform's own words when it refused, or ours when we chose
            // not to ask. Shown verbatim: paraphrasing a Meta error code is how
            // an operator ends up unable to look it up.
            'error' => $this->error,
            'attempts' => (int) $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
            // Lets the dashboard link a delivered row straight to the thread it
            // opened.
            'conversation_id' => $this->conversation_id,
            'contact' => ContactResource::make($this->whenLoaded('contact')),
        ];
    }
}
