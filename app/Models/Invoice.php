<?php

namespace App\Models;

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'purpose',
        'apiway_subscription_id',
        'trained_agent_hire_id',
        'status',
        'payment_method',
        // Which gateway the charge was made on (null = payment service).
        'gateway',
        'amount_cents',
        // Unused value of the plan this one replaced, already taken off
        // amount_cents. Kept so the period's full value can be read back.
        'proration_credit_cents',
        'currency',
        'period_start',
        'period_end',
        'due_date',
        'paid_at',
        'payment_id',
        'order_reference',
        'pix_qr_code',
        'pix_qr_code_base64',
        'pix_copy_paste',
        'pix_expires_at',
        'checkout_url',
        'checkout_expires_at',
        'idempotency_key',
        'meta',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'payment_method' => PaymentMethod::class,
        'purpose' => InvoicePurpose::class,
        'amount_cents' => 'integer',
        'proration_credit_cents' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'pix_expires_at' => 'datetime',
        'checkout_expires_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function apiwaySubscription()
    {
        return $this->belongsTo(ApiwaySubscription::class);
    }

    public function trainedAgentHire()
    {
        return $this->belongsTo(TrainedAgentHire::class);
    }

    /** The nota fiscal issued for this invoice (Brazil only), if any. */
    public function fiscalInvoice()
    {
        return $this->hasOne(FiscalInvoice::class);
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
