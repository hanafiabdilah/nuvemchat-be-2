<?php

namespace App\Models;

use App\Enums\Tutorial\TutorialVideoStatus;
use App\Services\Media\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tutorial video in the platform's library (Back Office → Tutorials).
 *
 * Platform content, not tenant data: the file lives on the published disk
 * under `tutorials/`, outside every workspace's storage meter, and its address
 * is permanent — the dashboard caches nothing about it, but a video that
 * stopped playing in an open drawer would be the wrong kind of surprise.
 */
class TutorialVideo extends Model
{
    protected $fillable = [
        'title',
        'description',
        'locale',
        'is_active',
        'auto_open',
        'status',
        'error',
        'source_path',
        'original_name',
        'path',
        'poster_path',
        'thumbnail_path',
        'duration_seconds',
        'width',
        'height',
        'size_bytes',
        'created_by_admin_id',
        'processed_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_open' => 'boolean',
        'status' => TutorialVideoStatus::class,
        'duration_seconds' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'size_bytes' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function topics(): HasMany
    {
        return $this->hasMany(TutorialVideoTopic::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(TutorialVideoView::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /** What the dashboard may show: switched on and finished processing. */
    public function scopePlayable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', TutorialVideoStatus::Ready->value)->whereNotNull('path');
    }

    public function isPlayable(): bool
    {
        return $this->is_active && $this->status === TutorialVideoStatus::Ready && $this->path !== null;
    }

    public function videoUrl(): ?string
    {
        return $this->path ? MediaStorage::publishedUrl($this->path) : null;
    }

    /**
     * The picture shown before the video plays: the thumbnail the admin chose,
     * else the frame ffmpeg drew from the video.
     */
    public function posterUrl(): ?string
    {
        $path = $this->thumbnail_path ?: $this->poster_path;

        return $path ? MediaStorage::publishedUrl($path) : null;
    }

    /** The frame drawn from the video, whatever thumbnail is chosen. */
    public function autoPosterUrl(): ?string
    {
        return $this->poster_path ? MediaStorage::publishedUrl($this->poster_path) : null;
    }

    /** @return list<string> */
    public function topicKeys(): array
    {
        return $this->topics->pluck('topic')->values()->all();
    }

    /** Every file this row owns on the published disk (the upload waiting on staging is not one). */
    public function storedPaths(): array
    {
        return array_values(array_unique(array_filter([$this->path, $this->poster_path, $this->thumbnail_path])));
    }
}
