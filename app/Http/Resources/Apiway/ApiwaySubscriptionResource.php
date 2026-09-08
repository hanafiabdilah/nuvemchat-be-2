<?php

namespace App\Http\Resources\Apiway;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiwaySubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'status' => $this->status,
            'cycle' => $this->cycle,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unit_price_cents,
            'total_price_cents' => $this->total_price_cents,
            'location_code' => $this->location_code,
            'expires_at' => $this->expires_at,
            // API Way instances are paid from the prepaid balance and renewed
            // by apiway:renew, so there is no standing card authorisation left
            // to describe — the two fields below used to report one.
            'autopay' => false,
            'payment_method' => 'balance',
            'created_at' => $this->created_at,
            'instances' => ApiwayInstanceResource::collection($this->whenLoaded('instances')),
        ];
    }
}
