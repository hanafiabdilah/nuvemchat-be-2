<?php

namespace App\Models;

use App\Models\Concerns\VisibleThroughCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sale being worked: one stage of one pipeline, about one customer. */
class Deal extends Model
{
    use VisibleThroughCustomer;

    protected $table = 'crm_deals';

    protected $fillable = [
        'tenant_id', 'pipeline_id', 'stage_id', 'customer_id', 'company_id', 'lead_id', 'title', 'description',
        'value_cents', 'currency', 'expected_close_date', 'status', 'owner_user_id', 'conversation_id',
        'position', 'stage_entered_at', 'won_at', 'lost_at', 'lost_reason',
    ];

    protected $casts = [
        'value_cents' => 'integer',
        'position' => 'integer',
        'expected_close_date' => 'date',
        'stage_entered_at' => 'datetime',
        'won_at' => 'datetime',
        'lost_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmPipelineStage::class, 'stage_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
