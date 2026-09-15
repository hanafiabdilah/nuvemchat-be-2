<?php

namespace App\Models;

use App\Services\Market\MarketResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain customers of one market reach the dashboard on.
 *
 * Never the platform domain's job: webhooks and signed links live on
 * PLATFORM_URL whatever market a workspace is in. A domain that is in no row
 * here belongs to the default market.
 */
class MarketDomain extends Model
{
    protected $fillable = [
        'market_code',
        'domain',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Stored in the exact form requests are matched in, so "APP.Pingly.id"
     * typed by a person can never be a row nothing ever matches. In save()
     * rather than a model event so a faked dispatcher can't skip it.
     */
    public function save(array $options = []): bool
    {
        $this->domain = MarketResolver::normalizeHost($this->domain);

        $saved = parent::save($options);

        MarketResolver::flush();

        return $saved;
    }

    public function delete(): ?bool
    {
        $deleted = parent::delete();

        MarketResolver::flush();

        return $deleted;
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_code', 'code');
    }
}
