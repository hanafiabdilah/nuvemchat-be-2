<?php

namespace App\Models;

use App\Enums\Crm\TaskPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A follow-up: something someone has to do, by when, about a customer, lead,
 * deal or company. `customer_id` is set whenever there is a person behind the
 * subject, so the task shows on their page and moves with a merge.
 */
class CrmTask extends Model
{
    protected $table = 'crm_tasks';

    protected $fillable = [
        'tenant_id', 'title', 'description', 'priority', 'due_at', 'remind_at', 'reminded_at', 'completed_at',
        'completed_by', 'assignee_user_id', 'created_by', 'subject_type', 'subject_id', 'customer_id', 'conversation_id',
    ];

    protected $casts = [
        'priority' => TaskPriority::class,
        'due_at' => 'datetime',
        'remind_at' => 'datetime',
        'reminded_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The tasks this user may see: everyone's in the workspace. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('crm_tasks.tenant_id', $user->tenant_id);
    }
}
