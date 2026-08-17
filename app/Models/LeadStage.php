<?php

namespace App\Models;

use App\Enums\Lead\StageKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One column of the board.
 */
class LeadStage extends Model
{
    protected $fillable = [
        'pipeline_id',
        'name',
        'color',
        'kind',
        'position',
    ];

    protected $casts = [
        'kind' => StageKind::class,
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(LeadPipeline::class, 'pipeline_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'stage_id');
    }
}
