<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person having played a tutorial video, and whether they reached its end. */
class TutorialVideoView extends Model
{
    protected $fillable = ['user_id', 'tutorial_video_id', 'first_viewed_at', 'completed_at'];

    protected $casts = [
        'first_viewed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(TutorialVideo::class, 'tutorial_video_id');
    }
}
