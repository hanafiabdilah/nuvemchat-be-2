<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of one card, written once and never touched again.
 *
 * This is the table the conversion report is made of. A board only knows where
 * its cards are standing right now; "40 leads entered Proposta in July and 11
 * of them closed" is unanswerable from a snapshot, and cannot be backfilled
 * afterwards without inventing the dates. Same lesson as conversations
 * .resolved_at: if the moment is not recorded when it happens, it is gone.
 */
class LeadStageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id',
        'tenant_id',
        'from_stage_id',
        'to_stage_id',
        'to_stage_name',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'to_stage_id');
    }

    /** Null means the system moved the card, not that the agent is gone. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
