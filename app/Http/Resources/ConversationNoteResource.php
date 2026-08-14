<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ConversationNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            // Named, not just credited: a note without an author reads like the
            // system wrote it. Null once the account is deleted.
            'author' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null,
            // Whether *this* reader may change it, so the panel does not offer
            // buttons the API will refuse.
            'can_edit' => Auth::user() ? $this->resource->isEditableBy(Auth::user()) : false,
            'created_at' => $this->created_at->timestamp,
            'updated_at' => $this->updated_at->timestamp,
        ];
    }
}
