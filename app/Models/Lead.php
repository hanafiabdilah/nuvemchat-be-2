<?php

namespace App\Models;

use App\Enums\Lead\LeadSource;
use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Enums\Lead\Temperature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to sell to one person.
 *
 * Lives across many conversations — the customer asking a price, going quiet,
 * and coming back next week is one lead, even though Pingly will have opened
 * three conversation rows for it. Ends exactly once, at won or lost, and then a
 * later purchase gets a fresh card rather than reopening this one.
 *
 * ⚠️ `open_contact_id` is a generated column: it holds contact_id while the
 * lead is open and NULL afterwards, under a unique index. Never write to it —
 * the database keeps it, and it is what guarantees one open lead per contact.
 */
class Lead extends Model
{
    protected $fillable = [
        'tenant_id',
        'contact_id',
        'pipeline_id',
        'stage_id',
        'owner_id',
        'source_connection_id',
        'title',
        'value',
        'currency',
        'status',
        'source',
        'temperature',
        'temperature_score',
        'last_inbound_at',
        'stage_changed_at',
        'lost_reason',
        'closed_at',
    ];

    protected $casts = [
        'status' => LeadStatus::class,
        'source' => LeadSource::class,
        'temperature' => Temperature::class,
        'temperature_score' => 'integer',
        'value' => 'decimal:2',
        'last_inbound_at' => 'datetime',
        'stage_changed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(LeadPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function stageEvents(): HasMany
    {
        return $this->hasMany(LeadStageEvent::class)->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', LeadStatus::Open);
    }

    /** What to put on the card: the sale's own name, else the person's. */
    public function displayTitle(): string
    {
        return $this->title
            ?: ($this->getRelationValue('contact')?->name ?? 'Lead #'.$this->id);
    }

    /**
     * Move to a stage, recording who did it and why the card is where it is.
     *
     * The event row is the point: without it the board can only ever answer
     * "how many are sitting here now", never "how many passed through this
     * month and how many made it out". Status, closed_at and lost_reason all
     * follow from the target stage's kind so that "won" can never mean two
     * different things depending on which code path moved the card.
     */
    public function moveToStage(LeadStage $stage, ?User $actor = null, ?string $lostReason = null): void
    {
        $from = $this->stage_id;
        $kind = $stage->kind;

        $this->forceFill([
            'stage_id' => $stage->id,
            'pipeline_id' => $stage->pipeline_id,
            'status' => $kind->toLeadStatus(),
            'stage_changed_at' => now(),
            'closed_at' => $kind->isTerminal() ? now() : null,
            // Only meaningful on a loss, and clearing it on any other move
            // keeps a stale reason from following a reopened card around.
            'lost_reason' => $kind === StageKind::Lost ? $lostReason : null,
        ])->save();

        $this->stageEvents()->create([
            'tenant_id' => $this->tenant_id,
            'from_stage_id' => $from,
            'to_stage_id' => $stage->id,
            'to_stage_name' => $stage->name,
            'user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
