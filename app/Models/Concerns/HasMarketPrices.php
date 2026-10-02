<?php

namespace App\Models\Concerns;

use App\Enums\Billing\BillingCycle;
use App\Models\Market;
use App\Models\MarketPrice;
use App\Services\Market\MarketResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something the platform prices per country by hand: a plan, a trained agent.
 *
 * The rule the whole trait exists for: **no row means not sold here.** Falling
 * back to the model's own `price_cents` would publish a Brazilian number in a
 * country nobody priced, and it would do it silently — the customer would see
 * a price, pay it, and the platform would find out at reconciliation. A market
 * without a row simply has nothing to show.
 *
 * A plan is also priced **per cycle**: one plan can be sold monthly and yearly,
 * each row naming its own cycle, instead of two plans whose quotas and features
 * have to be kept identical by hand. A trained agent is not a subscription and
 * its rows carry no cycle (see pricedPerCycle()).
 *
 * @property int $price_cents
 * @property string|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MarketPrice> $marketPrices
 */
trait HasMarketPrices
{
    /**
     * The market the in-memory price columns were resolved for, if any — see
     * applyMarketPrice(). Not an attribute: never saved, never serialised.
     */
    protected ?string $resolvedMarketCode = null;

    /**
     * A new row is priced in the platform's home market from its own columns.
     *
     * Without this, anything created outside the Back Office editor — a seeder,
     * a factory, the next admin screen — would exist priced nowhere and be
     * silently unsellable, including in the country it was written for. The
     * home market's price *is* `price_cents`; that is what the backfill
     * migration wrote for every row that already existed.
     *
     * In save() rather than a model event for the reason Tenant::save() gives:
     * `Event::fake()` silences model events (39 test files use it) and
     * `saveQuietly()` skips them in production code, and a price that can be
     * skipped two ways is not a rule.
     *
     * An explicit list still wins — syncMarketPrices() replaces everything.
     */
    public function save(array $options = []): bool
    {
        $saved = parent::save($options);

        if ($saved && $this->wasRecentlyCreated) {
            $this->seedHomeMarketPrice();
        }

        return $saved;
    }

    public function marketPrices(): MorphMany
    {
        return $this->morphMany(MarketPrice::class, 'priceable');
    }

    /**
     * Whether this kind of thing is sold per billing cycle (a plan) or once (a
     * trained agent). A fact about the model, like hasPaymentFlags().
     */
    public function pricedPerCycle(): bool
    {
        return in_array('billing_cycle', $this->getFillable(), true);
    }

    /**
     * The row for one country, or null when it is not sold there.
     *
     * For something priced per cycle, the row for `$cycle` — and when no cycle
     * is named, for the cycle this instance currently stands at. That is what
     * lets a plan resolved with applyMarketPrice($market, $cycle) be handed to
     * code that has never heard of cycles (the checkout, the plan change quote)
     * and still be charged the price of the cycle the customer picked.
     */
    public function priceForMarket(?string $marketCode, BillingCycle|string|null $cycle = null): ?MarketPrice
    {
        $code = strtoupper(trim((string) $marketCode));

        if ($code === '') {
            return null;
        }

        $rows = $this->pricesForMarket($code);

        if (! $this->pricedPerCycle()) {
            return $rows->first();
        }

        $cycle = $this->cycleValue($cycle ?? $this->getAttribute('billing_cycle'));

        return $cycle === null ? null : $rows->firstWhere('billing_cycle', $cycle);
    }

    /**
     * Every price this thing has in one country, shortest cycle first.
     *
     * @return Collection<int, MarketPrice>
     */
    public function pricesForMarket(?string $marketCode): Collection
    {
        $code = strtoupper(trim((string) $marketCode));

        // relationLoaded so a catalog listing costs one query, not one per row.
        $rows = $this->relationLoaded('marketPrices')
            ? $this->marketPrices->where('market_code', $code)
            : $this->marketPrices()->where('market_code', $code)->get();

        return $rows
            ->sortBy(fn (MarketPrice $price) => BillingCycle::tryFrom((string) $price->billing_cycle)?->rank() ?? 0)
            ->values();
    }

    public function isSoldIn(?string $marketCode, BillingCycle|string|null $cycle = null): bool
    {
        if ($cycle === null) {
            return $this->pricesForMarket($marketCode)->isNotEmpty();
        }

        return $this->priceForMarket($marketCode, $cycle) !== null;
    }

    /** The market applyMarketPrice() last resolved this instance for. */
    public function resolvedMarketCode(): ?string
    {
        return $this->resolvedMarketCode;
    }

    /**
     * The same model with this country's price in the columns every existing
     * reader already looks at.
     *
     * In memory only, never saved: `price_cents` and `currency` on the row
     * itself stay what the Back Office typed for the platform's home market, so
     * nothing that has not been taught about markets starts reading a rupiah
     * amount as reais.
     */
    public function applyMarketPrice(?string $marketCode, BillingCycle|string|null $cycle = null): static
    {
        $this->resolvedMarketCode = strtoupper(trim((string) $marketCode)) ?: null;

        if ($this->pricedPerCycle()) {
            $rows = $this->pricesForMarket($marketCode);

            // The cycle asked for; else the one this plan already stands at;
            // else the shortest one sold here. Never a cycle with no row: a
            // plan whose own cycle is not sold in this country must still show
            // a price that is, not the home market's number.
            $price = $cycle !== null
                ? $rows->firstWhere('billing_cycle', $this->cycleValue($cycle))
                : ($rows->firstWhere('billing_cycle', $this->cycleValue($this->getAttribute('billing_cycle'))) ?? $rows->first());
        } else {
            $price = $this->priceForMarket($marketCode);
        }

        if ($price !== null) {
            $this->setAttribute('price_cents', $price->amount_cents);
            $this->setAttribute('currency', $price->currency);

            if ($this->pricedPerCycle() && $price->billing_cycle) {
                $this->setAttribute('billing_cycle', $price->billing_cycle);
            }

            // The payment methods travel with the price, so every reader that
            // already asks a plan whether it takes Pix gets the answer for the
            // country it was resolved for — without being taught about markets.
            if ($this->hasPaymentFlags()) {
                foreach (['card_enabled', 'pix_enabled'] as $flag) {
                    $this->setAttribute($flag, (bool) $price->{$flag});
                }
            }
        }

        return $this;
    }

    /**
     * Whether this kind of thing is bought at a checkout at all.
     *
     * A trained agent shares the price table and has no such columns: it is paid
     * for out of the prepaid balance, where there is no method to choose.
     */
    protected function hasPaymentFlags(): bool
    {
        // ⚠️ A fact about the model, not about which attributes happen to be
        // hydrated. Reading getAttributes() alone meant a plan created without
        // naming these columns — which is exactly what the Back Office editor
        // sends now that the methods moved per country — silently dropped every
        // per-market method on the way in, and the plan came out selling Pix
        // everywhere. A test caught it; nothing on screen would have.
        return in_array('card_enabled', $this->getFillable(), true)
            || array_key_exists('card_enabled', $this->getAttributes());
    }

    /** Only the ones on sale in this country. */
    public function scopeSoldIn(Builder $query, ?string $marketCode): Builder
    {
        $code = strtoupper(trim((string) $marketCode));

        return $query->whereHas('marketPrices', fn (Builder $q) => $q->where('market_code', $code));
    }

    /**
     * Replace this thing's whole price list.
     *
     * Whole rather than merged, because that is what the editor sends: a market
     * (or a cycle) the admin removed from the form has to stop being sold, and a
     * merge would leave it on sale at a price no longer on screen.
     *
     * The currency is taken from the market, not from the caller — it is the
     * market's, and letting a form set it would allow a country to be priced in
     * a currency its workspaces cannot pay in.
     *
     * Two shapes:
     *  - a list of rows `{market_code, billing_cycle, amount_cents, …}` — the
     *    editor that knows about cycles; replaces every cycle.
     *  - `market code => amount | row` — the older editor, which knew one price
     *    per country. It speaks for the plan's own cycle only, so a client that
     *    never heard of a yearly price cannot take one off sale by saving.
     *
     * @param  array<int|string, mixed>  $prices
     */
    public function syncMarketPrices(array $prices): void
    {
        $currencies = Market::query()->pluck('currency', 'code');
        $perCycle = $this->pricedPerCycle();
        $isList = array_is_list($prices) && $prices !== [] && is_array($prices[0]) && array_key_exists('market_code', $prices[0]);
        $ownCycle = $perCycle ? ($this->cycleValue($this->getAttribute('billing_cycle')) ?? BillingCycle::Monthly->value) : null;
        $keep = [];

        foreach ($prices as $code => $value) {
            if ($value === null) {
                continue;
            }

            // A bare number is still accepted: most callers only set a price,
            // and the payment methods are a plan-only concern.
            $row = is_array($value) ? $value : ['amount_cents' => $value];
            $code = strtoupper(trim((string) ($isList ? ($row['market_code'] ?? '') : $code)));
            $cycle = $perCycle
                ? ($isList ? $this->cycleValue($row['billing_cycle'] ?? null) : $ownCycle)
                : null;

            if ($code === '' || ! isset($currencies[$code]) || ($perCycle && $cycle === null)) {
                continue;
            }

            $attributes = [
                'amount_cents' => max(0, (int) ($row['amount_cents'] ?? 0)),
                'currency' => $currencies[$code],
            ];

            if ($this->hasPaymentFlags()) {
                // Absent means "as it was", so a caller that does not know about
                // methods cannot silently turn one off.
                foreach (['card_enabled', 'pix_enabled'] as $flag) {
                    if (array_key_exists($flag, $row)) {
                        $attributes[$flag] = (bool) $row[$flag];
                    }
                }
            }

            $this->marketPrices()->updateOrCreate(
                ['market_code' => $code, 'billing_cycle' => $cycle],
                $attributes,
            );

            $keep[] = $code.'|'.$cycle;
        }

        $this->marketPrices()
            ->when(! $isList && $perCycle, fn ($q) => $q->where('billing_cycle', $ownCycle))
            ->get()
            ->reject(fn (MarketPrice $price) => in_array($price->market_code.'|'.$price->billing_cycle, $keep, true))
            ->each->delete();

        $this->unsetRelation('marketPrices');
    }

    /**
     * Price this row in the home market, from the columns it was created with.
     *
     * Silent when that market has no row yet (a fresh database mid-migration):
     * failing to create a plan because the country table is empty would be a
     * worse answer than a plan somebody still has to price.
     */
    protected function seedHomeMarketPrice(): void
    {
        $code = MarketResolver::defaultCode();
        $market = Market::query()->find($code);

        if ($market === null) {
            return;
        }

        $this->marketPrices()->firstOrCreate(
            [
                'market_code' => $market->code,
                'billing_cycle' => $this->pricedPerCycle()
                    ? ($this->cycleValue($this->getAttribute('billing_cycle')) ?? BillingCycle::Monthly->value)
                    : null,
            ],
            [
                'amount_cents' => max(0, (int) ($this->price_cents ?? 0)),
                'currency' => $market->currency,
                // ⚠️ `?? true`, never a bare cast: a plan created without naming
                // these columns leaves the attribute unset, and casting that to
                // false would seed a home-market price selling no payment method
                // at all — a plan nobody could ever buy, from a line nobody
                // wrote. The table's default is true, so that is what an unset
                // attribute means here.
                ...($this->hasPaymentFlags() ? [
                    'card_enabled' => (bool) ($this->getAttribute('card_enabled') ?? true),
                    'pix_enabled' => (bool) ($this->getAttribute('pix_enabled') ?? true),
                ] : []),
            ],
        );
    }

    /**
     * The price list as the Back Office edits it.
     *
     * @return list<array{market_code: string, billing_cycle: ?string, amount_cents: int, currency: string, card_enabled?: bool, pix_enabled?: bool}>
     */
    public function marketPriceList(): array
    {
        $withMethods = $this->hasPaymentFlags();

        return $this->marketPrices()
            ->orderBy('market_code')
            ->get()
            ->sortBy(fn (MarketPrice $price) => [$price->market_code, BillingCycle::tryFrom((string) $price->billing_cycle)?->rank() ?? 0])
            ->map(fn (MarketPrice $price) => [
                'market_code' => $price->market_code,
                'billing_cycle' => $price->billing_cycle,
                'amount_cents' => $price->amount_cents,
                'currency' => $price->currency,
                ...($withMethods ? [
                    'card_enabled' => (bool) $price->card_enabled,
                    'pix_enabled' => (bool) $price->pix_enabled,
                ] : []),
            ])
            ->values()
            ->all();
    }

    private function cycleValue(BillingCycle|string|null $cycle): ?string
    {
        if ($cycle instanceof BillingCycle) {
            return $cycle->value;
        }

        return BillingCycle::tryFrom((string) $cycle)?->value;
    }
}
