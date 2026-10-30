<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry on a CRM timeline: what happened to a customer, company, lead or
 * deal, when, and who did it — or what someone logged (a note, a call, a
 * meeting, an e-mail). Written through App\Services\Crm\CrmTimeline only.
 */
class CrmActivity extends Model
{
    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'customer_id',
        'type',
        'body',
        'user_id',
        'conversation_id',
        'data',
        'occurred_at',
    ];

    protected $casts = [
        'data' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The entries this user may read: the whole workspace's. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('crm_activities.tenant_id', $user->tenant_id);
    }
}
