<?php

namespace App\Models;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'status',
        'payment_method',
        'amount_cents',
        'currency',
        'period_start',
        'period_end',
        'due_date',
        'paid_at',
        'mp_payment_id',
        'mp_preapproval_id',
        'pix_qr_code',
        'pix_qr_code_base64',
        'pix_copy_paste',
        'pix_expires_at',
        'idempotency_key',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'payment_method' => PaymentMethod::class,
        'amount_cents' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'pix_expires_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Pending->value);
    }

    public function scopeDueBefore(Builder $query, $date): Builder
    {
        return $query->whereDate('due_date', '<=', $date);
    }
}
