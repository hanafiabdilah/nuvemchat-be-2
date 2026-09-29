<?php

namespace App\Models;

use App\Enums\Catalog\StockMovementReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to a variant's stock. Append-only; see StockService. */
class StockMovement extends Model
{
    protected $fillable = [
        'tenant_id',
        'product_variant_id',
        'delta',
        'stock_after',
        'reason',
        'order_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'delta' => 'integer',
        'stock_after' => 'integer',
        'reason' => StockMovementReason::class,
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
