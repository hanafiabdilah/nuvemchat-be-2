<?php

namespace App\Models;

use App\Enums\Crm\CustomerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A person, above their channel contacts: the CRM's one identity per customer.
 * Each `contacts` row (one identity on one channel) links to one of these.
 */
class Customer extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'company_id',
        'job_title',
        'owner_user_id',
        'status',
        'source',
        'custom_fields',
    ];

    protected $casts = [
        'status' => CustomerStatus::class,
        'custom_fields' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The channel identities this person writes from. Groups never link. */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CrmTask::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(CustomerRelationship::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject');
    }

    /** Every conversation of every linked contact. */
    public function conversations(): HasManyThrough
    {
        return $this->hasManyThrough(Conversation::class, Contact::class);
    }

    /**
     * The customers this user may open: everyone in the workspace, like the
     * contact book these pages replace. Their conversations are another
     * matter — those always go through Conversation::visibleTo(), so an agent
     * kept off a connection still cannot read its threads from here.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('customers.tenant_id', $user->tenant_id);
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
