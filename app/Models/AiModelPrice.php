<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What one model costs a workspace, and what margin the platform takes on it.
 *
 * See the migration for why the list prices here are shown but not billed.
 */
class AiModelPrice extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'label',
        'input_usd_per_1m',
        'output_usd_per_1m',
        'markup_pct',
        'is_listed',
        'sort_order',
    ];

    protected $casts = [
        'input_usd_per_1m' => 'decimal:4',
        'output_usd_per_1m' => 'decimal:4',
        'markup_pct' => 'decimal:2',
        'is_listed' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** On the customer's price list. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_listed', true);
    }

    /**
     * The row governing a run, or null when the model was never priced.
     *
     * Matched case-insensitively on both halves: the provider arrives from the
     * hub as `OPENAI` and is typed into the Back Office as anything, and a
     * margin that silently stops applying because of capitalisation is the kind
     * of bug that is only ever found in a revenue report.
     */
    public static function forRun(?string $provider, ?string $model): ?self
    {
        if ($provider === null || $model === null) {
            return null;
        }

        return static::query()
            ->whereRaw('UPPER(provider) = ?', [strtoupper($provider)])
            ->whereRaw('LOWER(model) = ?', [strtolower($model)])
            ->first();
    }
}
