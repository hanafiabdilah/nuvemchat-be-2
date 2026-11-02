<?php

namespace App\Models;

use App\Enums\Billing\Addon;
use App\Enums\Billing\BillingCycle;
use App\Models\Concerns\HasMarketPrices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use HasMarketPrices;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'currency',
        'billing_cycle',
        'trial_days',
        'quotas',
        'features',
        'is_active',
        'is_public',
        'sort_order',
        'card_enabled',
        'pix_enabled',
        'mp_preapproval_plan_id',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'billing_cycle' => BillingCycle::class,
        'trial_days' => 'integer',
        'quotas' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
        'card_enabled' => 'boolean',
        'pix_enabled' => 'boolean',
    ];

    /**
     * Point the plan's own columns at what its price list now says.
     *
     * A plan is priced per country and per cycle (market_prices), but a few
     * readers still look at the row itself — reports, the default cycle a
     * catalog opens on, the home-market price a fresh row is seeded from. After
     * the price list changes they are rewritten from it: the shortest cycle sold
     * in the home market (else anywhere), at that price. Nobody types them.
     */
    public function syncPrimaryCycle(): void
    {
        $rows = $this->marketPrices()->get();
        $home = \App\Services\Market\MarketResolver::defaultCode();
        $rank = fn ($price) => BillingCycle::tryFrom((string) $price->billing_cycle)?->rank() ?? 99;

        $primary = $rows->where('market_code', $home)->sortBy($rank)->first()
            ?? $rows->sortBy($rank)->first();

        if ($primary === null || BillingCycle::tryFrom((string) $primary->billing_cycle) === null) {
            return;
        }

        $columns = [
            'billing_cycle' => $primary->billing_cycle,
            'price_cents' => $primary->market_code === $home ? (int) $primary->amount_cents : (int) $this->getRawOriginal('price_cents'),
        ];

        // ⚠️ A query, not save(): save() on a row created in this request would
        // run HasMarketPrices' home-market seeding again, and for a plan sold
        // only abroad that would put it on sale at home at a price nobody typed.
        static::query()->whereKey($this->getKey())->update($columns);
        $this->forceFill($columns)->syncOriginalAttributes(array_keys($columns));
    }

    /**
     * One unit of each extra this plan sells in a country, for a cycle, by
     * add-on key.
     *
     * An extra is sold only where both hold: the plan limits what it raises
     * (an unlimited quota has nothing to add to) and a price was typed for it
     * on this country's row for this cycle. The cycle defaults to the one this
     * instance stands at — the one applyMarketPrice() resolved it to.
     *
     * @return array<string, int>
     */
    public function addonUnitPrices(?string $marketCode, BillingCycle|string|null $cycle = null): array
    {
        $price = $this->priceForMarket($marketCode, $cycle);

        if ($price === null) {
            return [];
        }

        $quotas = $this->quotas ?? [];
        $prices = [];

        foreach (Addon::cases() as $addon) {
            $unit = $price->addonUnitCents($addon);

            if ($unit !== null && $addon->limitedBy($quotas)) {
                $prices[$addon->value] = $unit;
            }
        }

        return $prices;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}
