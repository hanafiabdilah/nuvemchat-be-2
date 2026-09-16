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
                        // Per country, because Pix is: one global checkbox used
                        // to offer it everywhere the moment a plan was priced
                        // for a second country.
                        'card_enabled' => (bool) $price->card_enabled,
                        'pix_enabled' => (bool) $price->pix_enabled,
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
            'card_enabled' => $this->card_enabled,
            'pix_enabled' => $this->pix_enabled,
            'created_at' => $this->created_at,
        ];
    }
}
