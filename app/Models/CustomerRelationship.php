<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer to another ("spouse", "colleague"), one row per direction. */
class CustomerRelationship extends Model
{
    protected $fillable = ['tenant_id', 'customer_id', 'related_customer_id', 'label'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'related_customer_id');
    }
}
