<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One flow text, translated into one language — the cache behind a flow that
 * answers each customer in their own language. See FlowLocalization.
 */
class FlowTextTranslation extends Model
{
    protected $fillable = [
        'tenant_id',
        'language',
        'source_hash',
        'text',
    ];
}
