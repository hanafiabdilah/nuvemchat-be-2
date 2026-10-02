<?php

namespace App\Http\Resources\Billing;

use App\Enums\Billing\BillingCycle;
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
                    ->sortBy(fn ($price) => [$price->market_code, BillingCycle::tryFrom((string) $price->billing_cycle)?->rank() ?? 0])
                    ->map(fn ($price) => [
                        'market_code' => $price->market_code,
                        'billing_cycle' => $price->billing_cycle,
                        'amount_cents' => $price->amount_cents,
                        'currency' => $price->currency,
                        'card_enabled' => (bool) $price->card_enabled,
                        'pix_enabled' => (bool) $price->pix_enabled,
                    ])
                    ->values(),
            ),
            // The cycle `price_cents` above is for — the one asked for, or the
            // shortest sold in this market.
            'billing_cycle' => $this->billing_cycle,
            // Every cycle this plan is sold at in the market it was resolved
            // for, shortest first: what the catalogue's cycle switch is built
            // from. One plan, several prices — not one plan per cycle.
            'cycle_prices' => $this->when(
                $this->resolvedMarketCode() !== null,
                fn () => $this->pricesForMarket($this->resolvedMarketCode())
                    ->filter(fn ($price) => BillingCycle::tryFrom((string) $price->billing_cycle) !== null)
                    ->map(fn ($price) => [
                        'billing_cycle' => $price->billing_cycle,
                        'price_cents' => $price->amount_cents,
                        'currency' => $price->currency,
                    ])
                    ->values(),
            ),
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
