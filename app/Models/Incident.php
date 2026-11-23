<?php

namespace App\Models;

use App\Enums\Incident\IncidentSource;
use App\Enums\Incident\IncidentStatus;
use App\Enums\Incident\MatchMode;
use App\Enums\Incident\ReplyMode;
use App\Enums\Incident\ResolutionAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something is broken, and every customer who writes in on these connections
 * is told so — once — until it is fixed. See App\Services\Incident.
 *
 * @property IncidentStatus $status
 * @property MatchMode $match_mode
 * @property ReplyMode $reply_mode
 * @property ResolutionAudience $resolution_audience
 * @property IncidentSource $source
 * @property array<int, string>|null $keywords
 * @property array<int, string>|null $exclude_keywords
 */
class Incident extends Model
{
    /** How long one may run, so a forgotten incident cannot answer customers for weeks. */
    public const MAX_DURATION_MINUTES = 72 * 60;

    public const MIN_DURATION_MINUTES = 15;

    public const DEFAULT_DURATION_MINUTES = 4 * 60;

    /** The furthest back "also tell who wrote just before" may reach. */
    public const MAX_NOTIFY_RECENT_MINUTES = 24 * 60;

    public const MAX_KEYWORDS = 50;

    protected $fillable = [
        'tenant_id',
        'title',
        'status',
        'match_mode',
        'keywords',
        'exclude_keywords',
        'reply_mode',
        'ai_hub_agent_id',
        'ai_brief',
        'message',
        'media_url',
        'media_type',
        'reply_in_agent_threads',
        'continue_flow',
        'notify_recent_minutes',
        'resolution_message',
        'resolution_audience',
        'starts_at',
        'ends_at',
        'activated_at',
        'resolved_at',
        'reminder_sent_at',
        'source',
        'created_by',
        'resolved_by',
        'api_key_id',
    ];

    protected $casts = [
        'status' => IncidentStatus::class,
        'match_mode' => MatchMode::class,
        'reply_mode' => ReplyMode::class,
        'resolution_audience' => ResolutionAudience::class,
        'source' => IncidentSource::class,
        'keywords' => 'array',
        'exclude_keywords' => 'array',
        'reply_in_agent_threads' => 'boolean',
        'continue_flow' => 'boolean',
        'notify_recent_minutes' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'activated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class, 'incident_connections');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(IncidentDelivery::class);
    }

    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiHubAgent::class, 'ai_hub_agent_id');
    }

    public function writesWithAi(): bool
    {
        return $this->reply_mode === ReplyMode::Ai && $this->ai_hub_agent_id !== null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Answering right now. `ends_at` is read here as well as by the scheduler:
     * the minute between an incident running out and incidents:tick noticing
     * must not answer anybody.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::Active)->where('ends_at', '>', now());
    }

    /** Scheduled or live — what a new incident must not overlap on a connection. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [IncidentStatus::Scheduled, IncidentStatus::Active]);
    }

    public function isLive(): bool
    {
        return $this->status === IncidentStatus::Active && $this->ends_at?->isFuture();
    }

    /** @return array<int, string> */
    public function keywordList(): array
    {
        return array_values(array_filter((array) ($this->keywords ?? []), 'is_string'));
    }

    /** @return array<int, string> */
    public function excludeKeywordList(): array
    {
        return array_values(array_filter((array) ($this->exclude_keywords ?? []), 'is_string'));
    }
}
