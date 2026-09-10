<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A charge a flow issued, for the list on an integration's page.
 *
 * No Pix code: it is a payable instrument, and the list is for seeing what
 * happened, not for paying from.
 */
class FlowPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider?->value,
            'method' => $this->method,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'description' => $this->description,
            'status' => $this->status->value,
            'paid_late' => (bool) (($this->meta ?? [])['paid_late'] ?? false),
            'conversation_id' => $this->conversation_id,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
            ] : null),
            'flow' => $this->whenLoaded('flow', fn () => $this->flow ? [
                'id' => $this->flow->id,
                'name' => $this->flow->name,
            ] : null),
            'payment_url' => $this->payment_url,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
