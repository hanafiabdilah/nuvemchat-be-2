<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One place a tutorial video shows, and its position there. */
class TutorialVideoTopic extends Model
{
    public $timestamps = false;

    protected $fillable = ['tutorial_video_id', 'topic', 'position'];

    protected $casts = ['position' => 'integer'];

    public function video(): BelongsTo
    {
        return $this->belongsTo(TutorialVideo::class, 'tutorial_video_id');
    }
}
