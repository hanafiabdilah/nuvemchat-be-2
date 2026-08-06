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
            'autopay' => $this->mp_preapproval_id !== null && ! ($this->meta['autopay_off'] ?? false),
            'payment_method' => $this->mp_preapproval_id ? 'card' : 'pix',
            'created_at' => $this->created_at,
            'instances' => ApiwayInstanceResource::collection($this->whenLoaded('instances')),
        ];
    }
}
