<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message the API Way core is still holding in its anti-ban queue.
 *
 * Only a work list for App\Services\Message\ApiwaySendQueue — the state the
 * dashboard draws lives on the message (`meta.send_queue`), and the row is
 * deleted once the core says the message went out or did not.
 */
class ApiwayQueuedSend extends Model
{
    protected $fillable = [
        'message_id',
        'connection_id',
        'queue_id',
        'check_after',
    ];

    protected $casts = [
        'check_after' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
