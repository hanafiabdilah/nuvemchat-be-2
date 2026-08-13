<?php

namespace App\Models;

use App\Enums\Instagram\PostMediaType;
use App\Enums\Instagram\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A post composed in Pingly: a draft, a schedule, or the receipt for one that
 * has landed on Instagram.
 */
class InstagramPost extends Model
{
    protected $fillable = [
        'tenant_id',
        'connection_id',
        'created_by',
        'status',
        'media_type',
        'caption',
        'scheduled_at',
        'published_at',
        'ig_container_id',
        'ig_media_id',
        'permalink',
        'error',
        'attempts',
    ];

    protected $casts = [
        'status' => PostStatus::class,
        'media_type' => PostMediaType::class,
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connection(): BelongsTo
    {
        // `connection` is a reserved Eloquent property (the DB connection name),
        // so this relation must be read through getRelationValue(). Same trap as
        // Message, Conversation and Broadcast.
        return $this->belongsTo(Connection::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InstagramPostItem::class)->orderBy('position');
    }

    /** The connection model, past the reserved-property trap above. */
    public function connectionModel(): ?Connection
    {
        return $this->getRelationValue('connection');
    }

    /**
     * Whether the scheduler should hand this to the queue now.
     *
     * Deliberately inclusive of the past: a scheduler tick that was missed (a
     * deploy, a stopped worker) must still fire the post late rather than skip
     * it, because a marketing post nobody published is worse than a late one.
     */
    public function isDue(): bool
    {
        return $this->status === PostStatus::Scheduled
            && $this->scheduled_at !== null
            && $this->scheduled_at->isPast();
    }
}
