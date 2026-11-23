<?php

namespace App\Models;

use App\Enums\Incident\MatchMode;
use App\Enums\Incident\ReplyMode;
use Illuminate\Database\Eloquent\Model;

/**
 * A ready incident ("Instabilidade de rede", "Manutenção programada"): the
 * words and the keywords written calmly beforehand, so the day it happens is a
 * matter of picking it and pressing activate.
 *
 * @property MatchMode $match_mode
 */
class IncidentTemplate extends Model
{
    public const MAX_PER_TENANT = 30;

    protected $fillable = [
        'tenant_id',
        'name',
        'title',
        'match_mode',
        'keywords',
        'exclude_keywords',
        'reply_mode',
        'ai_hub_agent_id',
        'ai_brief',
        'message',
        'media_url',
        'media_type',
        'resolution_message',
        'duration_minutes',
    ];

    protected $casts = [
        'match_mode' => MatchMode::class,
        'reply_mode' => ReplyMode::class,
        'keywords' => 'array',
        'exclude_keywords' => 'array',
        'duration_minutes' => 'integer',
    ];
}
