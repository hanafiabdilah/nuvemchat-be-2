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

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_code', 'code');
    }
}
