<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'external_id',
        'connection_id',
        'user_id', // agent
        'status',
        'needs_human',
        'handoff_reason',
        'handoff_at',
        'last_message_at',
    ];

    protected $casts = [
        'status' => Status::class,
        'needs_human' => 'boolean',
        'handoff_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    // Eager-loadable variant of last_message (one query per page instead of one
    // per conversation). Same ordering as the accessor below.
    public function lastMessage()
    {
        return $this->hasOne(Message::class)->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    public function getLastMessageAttribute()
    {
        if ($this->relationLoaded('lastMessage')) {
            return $this->getRelation('lastMessage');
        }

        return $this->messages()->latest('created_at')->latest('id')->first();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'conversation_tags');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flowState()
    {
        return $this->hasOne(FlowState::class);
    }

    /**
     * Whether the given user may read/act on this conversation.
     *
     * Rules (additive):
     * - Owner can access everything.
     * - The assigned agent can access their own conversation.
     * - E-mail is a shared inbox: any agent with access to the e-mail
     *   connection can read and reply without the conversation being
     *   assigned to them (there is no accept/assign step for e-mail).
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->hasRole('owner')) {
            return true;
        }

        if ($this->user_id !== null && (int) $this->user_id === (int) $user->id) {
            return true;
        }

        $connection = $this->connection;
        if ($connection && $connection->channel === Channel::Email) {
            return $connection->users->contains($user->id);
        }

        return false;
    }
}
