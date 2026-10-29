<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a "Comentário → Direct" rule did with one comment. Kept so the account's
 * page can answer "why didn't this person get the message?" without a log.
 */
class CommentDmRun extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'automation_id',
        'comment_id',
        'media_id',
        'commenter_id',
        'commenter_username',
        'comment_text',
        'status',
        'reason',
        'dm_message_id',
        'public_reply_id',
        'conversation_id',
        'error',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(CommentDmAutomation::class, 'automation_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
