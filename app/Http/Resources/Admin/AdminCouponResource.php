<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A coupon as the Back Office edits it, with what it has been used for. */
class AdminCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'scope' => $this->scope?->value,
            'percent_off' => $this->percent_off,
            'duration' => $this->duration?->value,
            'duration_cycles' => $this->duration_cycles,
            'applies_to_addons' => (bool) $this->applies_to_addons,
            'is_public' => (bool) $this->is_public,
            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'max_redemptions' => $this->max_redemptions,
            'plan_ids' => $this->plan_ids,
            'billing_cycles' => $this->billing_cycles,
            'market_codes' => $this->market_codes,
            'created_at' => $this->created_at?->toIso8601String(),
            'usage' => $this->getAttribute('usage'),
        ];
    }
}
