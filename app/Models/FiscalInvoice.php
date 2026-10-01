<?php

namespace App\Models;

use App\Enums\Billing\FiscalInvoiceStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A nota fiscal Pingly issued to one of its own customers for a paid invoice.
 *
 * Not to be confused with FlowInvoice: that one is a *workspace* issuing notas
 * to *its* customers from a flow, on its own Spedy account. This one is the
 * platform as prestador, on Pingly's Plugnotas account.
 */
class FiscalInvoice extends Model
{
    protected $fillable = [
        'invoice_id',
        'tenant_id',
        'provider',
        'status',
        'attempt',
        'reference',
        'provider_id',
        'protocol',
        'number',
        'verification_code',
        'amount_cents',
        'description',
        'message',
        'submitted_at',
        'checked_at',
        'issued_at',
        'cancelled_at',
        'meta',
    ];

    protected $casts = [
        'status' => FiscalInvoiceStatus::class,
        'attempt' => 'integer',
        'amount_cents' => 'integer',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta ?? [], $key, $default);
    }

    /**
     * Meta with $values merged in. A null value *removes* the key rather than
     * storing a JSON null: the sweep looks for `meta->cancel_requested_at` with
     * whereNotNull, and a JSON null is not an SQL NULL on MySQL.
     */
    public function withMeta(array $values): array
    {
        return array_filter(array_merge($this->meta ?? [], $values), fn ($value) => $value !== null);
    }
}
