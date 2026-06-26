<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'billing_cycle' => $this->billing_cycle,
            'trial_days' => $this->trial_days,
            'quotas' => $this->quotas ?? [],
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'sort_order' => $this->sort_order,
            'mp_card_enabled' => $this->mp_card_enabled,
            'mp_pix_enabled' => $this->mp_pix_enabled,
            'created_at' => $this->created_at,
        ];
    }
}
