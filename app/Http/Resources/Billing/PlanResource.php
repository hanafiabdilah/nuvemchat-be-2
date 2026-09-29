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
            // The price of whichever market this was resolved for — see
            // HasMarketPrices::applyMarketPrice(). Unresolved, it is the row's
            // own, which is what the Back Office list wants.
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            // Only for the Back Office editor: the whole per-country price
            // list, loaded on request rather than on every tenant's plan page.
            'prices' => $this->when(
                $this->relationLoaded('marketPrices'),
                fn () => $this->marketPrices
                    ->sortBy('market_code')
                    ->map(fn ($price) => [
                        'market_code' => $price->market_code,
                        'amount_cents' => $price->amount_cents,
                        'currency' => $price->currency,
                    ])
                    ->values(),
            ),
            'billing_cycle' => $this->billing_cycle,
            'trial_days' => $this->trial_days,
            'quotas' => $this->quotas ?? [],
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'sort_order' => $this->sort_order,
            // No `card_enabled` / `pix_enabled` any more: how a plan is paid is
            // decided by the gateway billing the workspace (GET
            // /billing/payment-methods → `offered`), not by a checkbox per plan.
            'created_at' => $this->created_at,
        ];
    }
}
