<?php

namespace App\Models;

use App\Enums\Market\MarketStatus;
use App\Services\Market\MarketResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A country the platform sells in.
 *
 * Today it carries the currency and the language, timezone and phone country a
 * new workspace starts with. Prices, payment methods and the products on sale
 * attach to it in later phases. A workspace belongs to one market forever —
 * see Tenant::booted().
 */
class Market extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'currency',
        'price_rounding_cents',
        'default_locale',
        'default_timezone',
        'phone_country',
        'status',
    ];

    protected $casts = [
        'status' => MarketStatus::class,
        'price_rounding_cents' => 'integer',
    ];

    /**
     * What a converted price is rounded up to here, in minor units.
     *
     * Only prices the platform converts pass through it (an AI run, an API Way
     * instance, a gigabyte of storage) — a price set per market is already the
     * number somebody chose. 1 means no rounding, which is what a market whose
     * prices were authored in its own currency wants.
     */
    public function roundingCents(): int
    {
        return max(1, (int) ($this->price_rounding_cents ?? 1));
    }

    /**
     * In save() rather than a model event, so a faked dispatcher or a quiet
     * save cannot slip past it — the same reason as Tenant::save().
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            if ($this->isDirty('code')) {
                throw new LogicException('A market code never changes: workspaces, prices and ledger rows refer to it.');
            }

            // Balances, invoices and ledger rows are amounts in this currency.
            // Changing it under existing workspaces relabels their money rather
            // than converting it.
            if ($this->isDirty('currency') && $this->tenants()->exists()) {
                throw new LogicException("Market {$this->code} already has workspaces, so its currency is fixed.");
            }
        }

        return parent::save($options);
    }

    /** The market used when nothing else decides — see config/markets.php. */
    public static function default(): self
    {
        return static::query()->findOrFail(MarketResolver::defaultCode());
    }

    public function domains(): HasMany
    {
        return $this->hasMany(MarketDomain::class, 'market_code', 'code');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'market_code', 'code');
    }
}
