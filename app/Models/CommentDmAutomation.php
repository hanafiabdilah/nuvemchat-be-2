<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "Comentário → Direct" rule on an Instagram account: which posts, which
 * comments, the private message sent to whoever wrote one, and the public
 * replies one of which is posted under it. See CommentDmAutomations.
 */
class CommentDmAutomation extends Model
{
    public const MATCH_ANY = 'any';

    public const MATCH_KEYWORDS = 'keywords';

    protected $fillable = [
        'tenant_id',
        'connection_id',
        'name',
        'enabled',
        'media_ids',
        'match_mode',
        'keywords',
        'public_replies',
        'dm_message',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'media_ids' => 'array',
        'keywords' => 'array',
        'public_replies' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CommentDmRun::class, 'automation_id');
    }
}
