<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A board deals move along, stage by stage. */
class CrmPipeline extends Model
{
    protected $table = 'crm_pipelines';

    protected $fillable = ['tenant_id', 'name', 'position', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
        'position' => 'integer',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(CrmPipelineStage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'pipeline_id');
    }
}
