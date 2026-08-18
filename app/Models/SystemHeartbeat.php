<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last time a background process said it was alive. One row per process,
 * rewritten in place. See {@see \App\Support\Heartbeat} for the write side.
 */
class SystemHeartbeat extends Model
{
    protected $fillable = ['name', 'beat_at', 'meta'];

    protected $casts = [
        'beat_at' => 'datetime',
        'meta' => 'array',
    ];
}
