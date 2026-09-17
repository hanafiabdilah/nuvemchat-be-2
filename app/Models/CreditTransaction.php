<?php

namespace App\Models;

use App\Enums\Credit\CreditTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a tenant's prepaid balance. Append-only: rows are never
 * updated or deleted, corrections are new rows of type `adjustment`.
 */
class CreditTransaction extends Model
{
    protected $fillable = [
        'tenant_id',
        'type',
        'amount_cents',
        'balance_after_cents',
        'currency',
        'ai_hub_run_id',
        'invoice_id',
        'reference',
        'cost_usd',
        // Renamed from usd_brl_rate when a second currency arrived. It was
        // missed here, so every debit since has mass-assigned a key that is not
        // fillable and lost its rate — the one number that makes an old charge
        // explainable months later.
        'usd_rate',
        'markup_pct',
        'description',
        'meta',
    ];

    protected $casts = [
        'type' => CreditTransactionType::class,
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'cost_usd' => 'decimal:8',
        'usd_rate' => 'decimal:4',
        'markup_pct' => 'decimal:2',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiHubRun::class, 'ai_hub_run_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
