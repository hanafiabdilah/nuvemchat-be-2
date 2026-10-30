<?php

namespace App\Models;

use App\Enums\Crm\AutomationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of a CRM automation: what it acted on and what each action did. */
class CrmAutomationRun extends Model
{
    protected $table = 'crm_automation_runs';

    protected $fillable = [
        'tenant_id', 'automation_id', 'trigger', 'subject_type', 'subject_id', 'subject_title', 'customer_id',
        'dedupe_key', 'status', 'results', 'error', 'finished_at',
    ];

    protected $casts = [
        'status' => AutomationRunStatus::class,
        'results' => 'array',
        'finished_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(CrmAutomation::class, 'automation_id');
    }
}
