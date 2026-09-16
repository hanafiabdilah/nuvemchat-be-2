<?php

namespace App\Models\Concerns;

use App\Models\Market;
use App\Models\MarketPrice;
use App\Services\Market\MarketResolver;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int $price_cents
 * @property string|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MarketPrice> $marketPrices
 */
trait HasMarketPrices
{
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

    /** The row for one country, or null when it is not sold there. */
    public function priceForMarket(?string $marketCode): ?MarketPrice
    {
        $code = strtoupper(trim((string) $marketCode));

        if ($code === '') {
            return null;
        }

        // relationLoaded so a catalog listing costs one query, not one per row.
        if ($this->relationLoaded('marketPrices')) {
            return $this->marketPrices->firstWhere('market_code', $code);
        }

        return $this->marketPrices()->where('market_code', $code)->first();
    }

    public function isSoldIn(?string $marketCode): bool
    {
        return $this->priceForMarket($marketCode) !== null;
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
    public function applyMarketPrice(?string $marketCode): static
    {
        $price = $this->priceForMarket($marketCode);

        if ($price !== null) {
            $this->setAttribute('price_cents', $price->amount_cents);
            $this->setAttribute('currency', $price->currency);
        }

        return $this;
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
     * the admin removed from the form has to stop being sold, and a merge would
     * leave it on sale at a price no longer on screen.
     *
     * The currency is taken from the market, not from the caller — it is the
     * market's, and letting a form set it would allow a country to be priced in
     * a currency its workspaces cannot pay in.
     *
     * @param  array<string, int|null>  $prices  market code => minor units (null removes)
     */
    public function syncMarketPrices(array $prices): void
    {
        $currencies = Market::query()->pluck('currency', 'code');
        $keep = [];

        foreach ($prices as $code => $amount) {
            $code = strtoupper(trim((string) $code));

            if ($code === '' || $amount === null || ! isset($currencies[$code])) {
                continue;
            }

            $this->marketPrices()->updateOrCreate(
                ['market_code' => $code],
                ['amount_cents' => max(0, (int) $amount), 'currency' => $currencies[$code]],
            );

            $keep[] = $code;
        }

        $this->marketPrices()->whereNotIn('market_code', $keep)->delete();
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
            ['market_code' => $market->code],
            [
                'amount_cents' => max(0, (int) ($this->price_cents ?? 0)),
                'currency' => $market->currency,
            ],
        );
    }

    /**
     * The price list as the Back Office edits it.
     *
     * @return list<array{market_code: string, amount_cents: int, currency: string}>
     */
    public function marketPriceList(): array
    {
        return $this->marketPrices()
            ->orderBy('market_code')
            ->get()
            ->map(fn (MarketPrice $price) => [
                'market_code' => $price->market_code,
                'amount_cents' => $price->amount_cents,
                'currency' => $price->currency,
            ])
            ->all();
    }
}
