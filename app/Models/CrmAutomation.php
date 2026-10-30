<?php

namespace App\Models;

use App\Enums\Crm\AutomationTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CRM automation: a trigger, the conditions a record has to meet, and the
 * actions to take on it (App\Services\Crm\Automation\CrmAutomations).
 */
class CrmAutomation extends Model
{
    protected $table = 'crm_automations';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'trigger', 'trigger_config', 'match', 'conditions', 'actions',
        'is_active', 'state', 'created_by',
    ];

    protected $casts = [
        'trigger' => AutomationTrigger::class,
        'trigger_config' => 'array',
        'conditions' => 'array',
        'actions' => 'array',
        'state' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CrmAutomationRun::class, 'automation_id');
    }
}
