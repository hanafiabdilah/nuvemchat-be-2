<?php

namespace App\Models;

use App\Enums\Catalog\OrderStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A cart the AI built in a conversation, and then the sale. See OrderService. */
class Order extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'contact_id',
        'flow_id',
        'flow_node_id',
        'status',
        'currency',
        'total_cents',
        'source',
        'paid_at',
        'closed_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'total_cents' => 'integer',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FlowPayment::class)->orderByDesc('id');
    }

    /** The charge that matters now: the newest one. */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(FlowPayment::class)->latestOfMany();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function formattedTotal(): string
    {
        return Money::format($this->total_cents, $this->currency);
    }

    /** "2x Camiseta Preta – G, 1x Boné" — what {{order_items}} reads. */
    public function itemsSummary(): string
    {
        return $this->items
            ->map(fn (OrderItem $item) => "{$item->quantity}x {$item->name}")
            ->implode(', ');
    }
}
