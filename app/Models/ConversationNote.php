<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal note about one conversation — written by an agent, read by the
 * workspace, never seen by the customer. See the migration for why it is not a
 * message.
 */
class ConversationNote extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** The author. Null once their account is gone; the note stays. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Notes are the author's to correct. An owner may also clear one out —
     * they answer for what the workspace keeps on record.
     */
    public function isEditableBy(User $user): bool
    {
        return ($this->user_id !== null && (int) $this->user_id === (int) $user->id)
            || $user->hasRole('owner');
    }
}
