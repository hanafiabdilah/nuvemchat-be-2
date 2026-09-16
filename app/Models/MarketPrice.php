<?php

namespace App\Models;

use App\Services\Market\MarketBillingMethods;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing's price in one country.
 *
 * Its existence is the decision to sell there; its amount is what somebody
 * typed for that country, never a conversion — see the migration.
 */
class MarketPrice extends Model
{
    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'market_code',
        'amount_cents',
        'currency',
        'card_enabled',
        'pix_enabled',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        // Which methods this thing is bought with **here**. Pix is a Brazilian
        // rail, so this is a per-country question, not a per-plan one. Ignored
        // for anything not bought at a checkout (a trained agent).
        'card_enabled' => 'boolean',
        'pix_enabled' => 'boolean',
    ];

    /**
     * The stored decision, narrowed by the rails this country actually has.
     *
     * ⚠️ Read-side on purpose, and this is the whole point of putting it here:
     * the Fase 3 backfill copied the old plan-wide `pix_enabled = true` onto
     * every market price row, Indonesia included, so the data already lies. An
     * accessor corrects every reader at once — BillingService::subscribe(),
     * which reads this property directly, and HasMarketPrices::applyMarketPrice(),
     * which copies it onto the plan — without a migration rewriting live rows.
     *
     * Patching those two call sites instead is how this rule comes back as a
     * bug in the third reader somebody adds.
     *
     * Accessors take precedence over the `$casts` entry below, which is kept
     * because it still governs writes.
     */
    protected function pixEnabled(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => (bool) $value
                && MarketBillingMethods::has($attributes['market_code'] ?? null, 'pix'),
        );
    }

    /** Same rule as Pix. Cards cross borders, so this narrows almost nothing. */
    protected function cardEnabled(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => (bool) $value
                && MarketBillingMethods::has($attributes['market_code'] ?? null, 'card'),
        );
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_code', 'code');
    }
}
