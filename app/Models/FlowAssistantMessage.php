<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the flow assistant's conversation about a flow.
 *
 * Shared, not personal: whoever can edit the flow reads the whole thread. See
 * the migration for why.
 */
class FlowAssistantMessage extends Model
{
    protected $fillable = [
        'flow_id',
        'tenant_id',
        'user_id',
        'role',
        'content',
        'blueprint',
        'warnings',
    ];

    protected $casts = [
        'blueprint' => 'array',
        'warnings' => 'array',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The thread for one flow, oldest first.
     *
     * Tenant-scoped as well as flow-scoped even though the flow id already
     * implies the tenant: this is the read path for a shared transcript, and
     * every read path in this codebase that skipped the tenant clause has
     * eventually leaked (see Conversation::visibleTo).
     */
    public function scopeForFlow(Builder $query, int $flowId, int $tenantId): Builder
    {
        return $query->where('flow_id', $flowId)
            ->where('tenant_id', $tenantId)
            ->orderBy('id');
    }
}
