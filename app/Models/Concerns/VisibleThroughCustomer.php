<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see a lead or a deal: everyone in its workspace — the same reach as
 * the customer it is about. A scope rather than a bare tenant check so every
 * read path keeps asking the one question, and narrowing it later stays one
 * change.
 */
trait VisibleThroughCustomer
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where($this->getTable().'.tenant_id', $user->tenant_id);
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
