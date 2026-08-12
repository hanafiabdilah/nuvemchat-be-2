<?php

namespace App\Models;

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use Illuminate\Database\Eloquent\Model;

/**
 * A broadcast campaign.
 *
 * ⚠️ Never `use Illuminate\Support\Facades\Broadcast` in a file that also uses
 * this model — the names collide. The `broadcast()` helper the send path calls
 * is a function, so it is unaffected.
 */
class Broadcast extends Model
{
    protected $fillable = [
        'tenant_id',
        'connection_id',
        'created_by',
        'tag_id',
        'name',
        'status',
        'content_type',
        'payload',
        'scheduled_at',
        'rate_per_minute',
        'total_recipients',
        'sent_count',
        'failed_count',
        'skipped_count',
        'started_at',
        'finished_at',
        'last_tick_at',
        'error',
    ];

    protected $casts = [
        'status' => Status::class,
        'content_type' => ContentType::class,
        'payload' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_tick_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connection()
    {
        // `connection` is a reserved Eloquent property name (the DB connection),
        // so the relation has to be read through getRelationValue() rather than
        // ->connection. See the same trap on Message/Conversation.
        return $this->belongsTo(Connection::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }

    public function recipients()
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /**
     * The channel this campaign sends on. Goes through getRelationValue for the
     * reason above — `$broadcast->connection` would hand back the DB connection
     * name, not the model.
     */
    public function channel(): ?\App\Enums\Connection\Channel
    {
        return $this->getRelationValue('connection')?->channel;
    }

    public function pendingCount(): int
    {
        return $this->recipients()
            ->where('status', RecipientStatus::Pending)
            ->count();
    }

    /**
     * How many recipients one batch may claim.
     *
     * Sized to roughly ten seconds of work so the pump re-reads the campaign's
     * status (pause, cancel) that often, and capped so a paused campaign never
     * has a long tail of already-claimed sends still going out.
     */
    public function batchSize(): int
    {
        return (int) max(1, min(25, ceil($this->rate_per_minute / 6)));
    }

    /** Seconds to wait before the next batch, to hold the configured rate. */
    public function batchDelaySeconds(): int
    {
        return (int) max(1, round($this->batchSize() / max(1, $this->rate_per_minute) * 60));
    }
}
