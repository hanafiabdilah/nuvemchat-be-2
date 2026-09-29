<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What is actually priced and counted. A simple product has exactly one, with
 * a null name.
 */
class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'name',
        'sku',
        'price_cents',
        'stock',
        'active',
        'position',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'stock' => 'integer',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** "Camiseta Preta – G", or just the product for a simple one. */
    public function displayName(): string
    {
        $product = (string) ($this->product?->name ?? '');
        $name = trim((string) $this->name);

        return $name === '' ? $product : trim($product.' – '.$name, ' –');
    }

    /** Whether stock is counted for this variant at all. */
    public function tracksStock(): bool
    {
        return $this->stock !== null;
    }

    public function isSellable(): bool
    {
        return $this->active
            && ! $this->trashed()
            && $this->product !== null
            && $this->product->active
            && ! $this->product->trashed();
    }

    public function formattedPrice(?string $currency): string
    {
        return Money::format($this->price_cents, $currency);
    }
}
