<?php

namespace App\Http\Resources;

use App\Models\Call;
use App\Services\Contact\ContactIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A call as every agent on the connection may see it: who is calling, who is
 * on it, since when. Metadata only — the SDPs never leave the server through
 * here (the answering browser gets the offer from the claim response alone).
 *
 * @mixin Call
 */
class CallResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(?Request $request = null): array
    {
        /** @var Call $call */
        $call = $this->resource;
        $contact = $call->getRelationValue('contact') ?? $call->contact()->first();
        $agent = $call->getRelationValue('agent') ?? ($call->answered_by_user_id ? $call->agent()->first() : null);
        $identity = ContactIdentity::for($contact);
        $user = $request?->user();

        return [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'connection_id' => $call->connection_id,
            'channel' => $call->channel?->value,
            'direction' => $call->direction,
            'status' => $call->status->value,
            'end_reason' => $call->end_reason,
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => ContactIdentity::name($contact) ?? ($identity['phone'] ?? null),
                'phone' => $identity['phone'] ?? null,
                'photo' => $contact->photo_profile_url,
            ] : null,
            'agent' => $agent ? ['id' => $agent->id, 'name' => $agent->name] : null,
            'ringing_at' => $call->ringing_at?->timestamp,
            'answered_at' => $call->answered_at?->timestamp,
            'ended_at' => $call->ended_at?->timestamp,
            'duration_seconds' => $call->duration_seconds,
            // 'recording' while the answering browser is capturing it — what
            // puts the "recording" mark on everybody's screen and tells the
            // browser to start.
            'recording' => $call->recording_status,
            // Only meaningful over HTTP, where we know who is asking; the
            // broadcast copy goes to everybody and leaves it out.
            'ringing_me' => $user ? $call->isRinging($user) : null,
        ];
    }
}
