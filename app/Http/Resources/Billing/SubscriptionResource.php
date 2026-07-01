<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entitlements = $this->entitlements();

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'billing_cycle' => $this->billing_cycle,
            'price_cents' => $this->price_cents,
            'quantity' => $this->quantity,
            'is_usable' => $this->isUsable(),
            'quotas' => $entitlements['quotas'],
            'features' => $entitlements['features'],
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'trial_ends_at' => $this->trial_ends_at,
            'grace_ends_at' => $this->grace_ends_at,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'user' => $this->tenant->relationLoaded('user') && $this->tenant->user ? [
                    'name' => $this->tenant->user->name,
                    'email' => $this->tenant->user->email,
                ] : null,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
