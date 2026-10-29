<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One file on a TikTok post: the video, or one of up to 35 photos. */
class TikTokPostItem extends Model
{
    protected $table = 'tiktok_post_items';

    protected $fillable = [
        'tiktok_post_id',
        'position',
        'media_type',
        'url',
        'path',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(TikTokPost::class, 'tiktok_post_id');
    }
}
