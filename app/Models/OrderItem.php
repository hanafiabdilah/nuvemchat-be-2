<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an order, with the name and price copied at the moment of sale. */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_variant_id',
        'name',
        'sku',
        'unit_price_cents',
        'quantity',
        'line_total_cents',
    ];

    protected $casts = [
        'unit_price_cents' => 'integer',
        'quantity' => 'integer',
        'line_total_cents' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
