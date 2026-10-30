<?php

namespace App\Models;

use App\Enums\Crm\StageKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One column of a pipeline. Its `kind` decides whether a deal in it is open, won or lost. */
class CrmPipelineStage extends Model
{
    protected $table = 'crm_pipeline_stages';

    protected $fillable = ['tenant_id', 'pipeline_id', 'name', 'position', 'probability', 'kind', 'color'];

    protected $casts = [
        'kind' => StageKind::class,
        'probability' => 'integer',
        'position' => 'integer',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }
}
