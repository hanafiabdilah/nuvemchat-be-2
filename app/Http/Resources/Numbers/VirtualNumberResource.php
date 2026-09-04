<?php

namespace App\Http\Resources\Numbers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rented number as its tenant sees it.
 *
 * ⚠️ `cost_cents` is deliberately absent. What the platform pays API Way is not
 * the customer's business, and a field shipped in JSON is published whether or
 * not any screen renders it.
 */
class VirtualNumberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'msisdn' => $this->msisdn,
            'app' => $this->app,
            'ddd' => $this->ddd,
            'region' => $this->region,
            'status' => $this->status->value,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'purchased_at' => $this->purchased_at?->toISOString(),
            'renews_at' => $this->renews_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            // Why a number the tenant did not cancel is gone: 'requested',
            // 'no_credit' or 'upstream'. Null on a live one.
            'cancel_reason' => $this->cancelReason(),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'messages' => VirtualNumberMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
