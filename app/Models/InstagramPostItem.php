<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of media on a post. A single image or video has exactly one of
 * these; a carousel has two to ten.
 */
class InstagramPostItem extends Model
{
    protected $fillable = [
        'instagram_post_id',
        'position',
        'media_type',
        'url',
        'path',
        'ig_container_id',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(InstagramPost::class, 'instagram_post_id');
    }

    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }
}
