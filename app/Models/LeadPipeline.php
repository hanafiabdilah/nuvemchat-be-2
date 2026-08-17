<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One funnel. Most tenants only ever have the one they were given.
 */
class LeadPipeline extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'is_default',
        'position',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(LeadStage::class, 'pipeline_id')->orderBy('position');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'pipeline_id');
    }

    /** Where a new lead starts: the leftmost open stage. */
    public function firstStage(): ?LeadStage
    {
        return $this->stages()->where('kind', 'open')->orderBy('position')->first();
    }

    /**
     * Make this the tenant's default, clearing whichever one held it.
     *
     * Two writes in a transaction rather than a unique index, because the index
     * would reject the moment where both rows are briefly flagged.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::where('tenant_id', $this->tenant_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->update(['is_default' => true]);
        });
    }
}
