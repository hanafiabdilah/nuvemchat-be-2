<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A list page's filters under a name — one person's, or the workspace's. */
class CrmSavedView extends Model
{
    public const ENTITIES = ['customers', 'companies', 'leads', 'deals', 'tasks'];

    protected $table = 'crm_saved_views';

    protected $fillable = ['tenant_id', 'user_id', 'entity', 'name', 'filters', 'shared'];

    protected $casts = [
        'filters' => 'array',
        'shared' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Views this user sees: their own, and the shared ones. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('tenant_id', $user->tenant_id)
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('shared', true));
    }
}
