<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One confirmed sale. See the migration and App\Services\Sales\SalesLedger.
 */
class Sale extends Model
{
    public const SOURCE_RECEIPT = 'receipt';

    public const SOURCE_PAYMENT = 'payment';

    public const KIND_FRONT = 'front';

    public const KIND_UPSELL = 'upsell';

    public const KINDS = [self::KIND_FRONT, self::KIND_UPSELL];

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'conversation_id',
        'contact_id',
        'source',
        'source_id',
        'amount_cents',
        'currency',
        'kind',
        'offer',
        'ad_id',
        'sold_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'sold_at' => 'datetime',
    ];
}
