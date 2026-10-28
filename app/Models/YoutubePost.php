<?php

namespace App\Models;

use App\Enums\Youtube\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video composed in Pingly: a draft, an upload on its way, a failure, or the
 * receipt for one that reached YouTube.
 */
class YoutubePost extends Model
{
    public const PRIVACY = ['public', 'unlisted', 'private'];

    protected $fillable = [
        'tenant_id',
        'youtube_account_id',
        'created_by',
        'status',
        'title',
        'description',
        'tags',
        'category_id',
        'privacy_status',
        'made_for_kids',
        'notify_subscribers',
        'video_path',
        'video_name',
        'video_size',
        'video_mime',
        'scheduled_at',
        'published_at',
        'yt_video_id',
        'upload_url',
        'uploaded_bytes',
        'error',
        'attempts',
    ];

    protected $casts = [
        'status' => PostStatus::class,
        'tags' => 'array',
        'made_for_kids' => 'boolean',
        'notify_subscribers' => 'boolean',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'video_size' => 'integer',
        'uploaded_bytes' => 'integer',
    ];

    /** The session URI authorises writes to the upload; it never leaves the server. */
    protected $hidden = ['upload_url'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(YoutubeAccount::class, 'youtube_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the upload should carry `publishAt`.
     *
     * Only a time still comfortably ahead: YouTube refuses a `publishAt` in the
     * past, and a schedule whose upload started late (a backed-up queue, a
     * worker restart) is better published now than failed.
     */
    public function isScheduledUpload(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->isAfter(now()->addMinutes(2));
    }

    public function watchUrl(): ?string
    {
        return $this->yt_video_id ? 'https://www.youtube.com/watch?v='.$this->yt_video_id : null;
    }
}
