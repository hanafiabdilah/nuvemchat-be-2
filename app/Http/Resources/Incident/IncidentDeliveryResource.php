<?php

namespace App\Http\Resources\Incident;

use App\Models\IncidentDelivery;
use App\Services\Contact\ContactIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One row of an incident's delivery list: who, on which connection, what they
 * said first, and what happened.
 *
 * The customer's words are trimmed to a preview — enough to see why a message
 * did not match and which keyword is missing, not a second inbox.
 *
 * @mixin IncidentDelivery
 */
class IncidentDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $conversation = $this->conversation;
        $contact = $conversation?->contact;

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'reason' => $this->reason,
            'error' => $this->error,
            'matched_keyword' => $this->matched_keyword,
            'excluded_keyword' => $this->excluded_keyword,
            'conversation_id' => $this->conversation_id,
            'connection_id' => $this->connection_id,
            'contact' => [
                'name' => ContactIdentity::name($contact) ?? $contact?->name,
                'phone' => ContactIdentity::for($contact)['phone'] ?? null,
            ],
            'trigger' => $this->triggerMessage ? [
                'type' => $this->triggerMessage->message_type->value,
                'body' => $this->triggerMessage->body !== null ? Str::limit((string) $this->triggerMessage->body, 280) : null,
                'at' => $this->triggerMessage->created_at?->toIso8601String(),
            ] : null,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
