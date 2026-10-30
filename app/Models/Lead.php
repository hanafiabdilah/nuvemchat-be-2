<?php

namespace App\Models;

use App\Enums\Crm\LeadStatus;
use App\Models\Concerns\VisibleThroughCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone who might buy, not yet a deal — always about a customer. */
class Lead extends Model
{
    use VisibleThroughCustomer;

    protected $table = 'crm_leads';

    protected $fillable = [
        'tenant_id', 'customer_id', 'title', 'description', 'status', 'score', 'source', 'value_cents',
        'owner_user_id', 'conversation_id', 'disqualified_reason', 'converted_deal_id', 'converted_at',
        'score_details', 'score_updated_at',
    ];

    protected $casts = [
        'status' => LeadStatus::class,
        'score' => 'integer',
        'value_cents' => 'integer',
        'converted_at' => 'datetime',
        'score_details' => 'array',
        'score_updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }
}
