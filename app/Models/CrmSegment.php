<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named group of customers defined by rules, evaluated live
 * (App\Services\Crm\CustomerFilter) — membership is never stored.
 */
class CrmSegment extends Model
{
    protected $table = 'crm_segments';

    protected $fillable = ['tenant_id', 'name', 'description', 'match', 'rules', 'created_by'];

    protected $casts = [
        'rules' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
