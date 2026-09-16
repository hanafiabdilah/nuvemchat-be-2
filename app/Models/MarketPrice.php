<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_code', 'code');
    }
}
