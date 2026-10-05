<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An inbound message held until its sender's `@lid` can be tied to a number.
 * See the migration for why these exist.
 */
class ParkedInboundMessage extends Model
{
    /** Older than this, a parked message is history, not something to act on. */
    public const REPLAY_WINDOW_HOURS = 24;

    /** How long a row is kept as a record after that. */
    public const RETENTION_DAYS = 7;

    protected $fillable = [
        'connection_id',
        'lid',
        'message_id',
        'payload',
        'replayed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'replayed_at' => 'datetime',
    ];
}
