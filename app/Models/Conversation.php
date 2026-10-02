<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Conversation\Type;
use App\Enums\Message\MessageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'external_id',
        'connection_id',
        'user_id', // agent
        'type',
        'status',
        'resolved_at',
        'resolved_by_user_id',
        'needs_human',
        'handoff_reason',
        'handoff_at',
        'muted_at',
        'exclusive_at',
        'exclusive_by_user_id',
        'last_message_at',
    ];

    protected $casts = [
        'type' => Type::class,
        'status' => Status::class,
        'resolved_at' => 'datetime',
        'needs_human' => 'boolean',
        'handoff_at' => 'datetime',
        'muted_at' => 'datetime',
        'exclusive_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    protected $attributes = [
        'type' => Type::Private->value,
    ];

    public function isGroup(): bool
    {
        return $this->type === Type::Group;
    }

    /**
     * Exclusive threads are read only by whoever handles them and by owners.
     *
     * Only one-to-one threads on a channel with an assignee can be exclusive:
     * a group has no single person handling it, and an e-mail inbox is shared
     * by design — hiding it from the people it is shared with is a contradiction.
     */
    public function isExclusive(): bool
    {
        return $this->exclusive_at !== null;
    }

    public function canBeExclusive(): bool
    {
        $connection = $this->getRelationValue('connection');

        return ! $this->isGroup()
            && $connection !== null
            && $connection->channel !== Channel::Email;
    }

    /**
     * Whether the user may read this thread's content (messages, notes,
     * variables). Seeing the row in the list is visibleTo()'s question; this
     * one adds exclusivity on top: owners always, otherwise only the agent
     * currently assigned — so a transfer hands the history over and a
     * colleague can never pull it out by taking the thread over.
     */
    public function isReadableBy(User $user): bool
    {
        if ($user->canAccessAllConnections()) {
            return (int) $this->getRelationValue('connection')?->tenant_id === (int) $user->tenant_id;
        }

        $connection = $this->getRelationValue('connection');

        if (! $connection
            || (int) $connection->tenant_id !== (int) $user->tenant_id
            || ! $user->canAccessConnection($connection)) {
            return false;
        }

        return ! $this->isExclusive()
            || ($this->user_id !== null && (int) $this->user_id === (int) $user->id);
    }

    /**
     * The users who may read an exclusive thread: its assignee and the
     * workspace's owners. Used to address the realtime events that carry
     * content, which for an exclusive thread go to these people's own
     * channels instead of the connection channel every agent shares.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function exclusiveReaderIds(): \Illuminate\Support\Collection
    {
        $connection = $this->getRelationValue('connection');

        if (! $connection) {
            return collect();
        }

        $owners = User::query()
            ->where('tenant_id', $connection->tenant_id)
            // Same check as canAccessAllConnections(), as a query: the owner
            // role is global, so it is matched by name rather than resolved
            // through Spatie (which looks names up per guard, not per tenant).
            ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
            ->pluck('id');

        return $owners
            ->when($this->user_id !== null, fn ($ids) => $ids->push($this->user_id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** Muted threads still collect messages; they just raise no toast or sound. */
    public function isMuted(): bool
    {
        return $this->muted_at !== null;
    }

    /**
     * The message the conversation list previews. Eager-loadable (one query per
     * page instead of one per row); same ordering as the accessor below.
     *
     * System notes are excluded. They are stored as messages so they take their
     * place in the timeline, but nobody said them — and the preview line exists
     * to show what was actually said. A row reading "Ana assumiu esta conversa."
     * buries the customer's last sentence, which is the one an agent scans the
     * list for, and it stands there until somebody writes again. Since accepting
     * a thread, transferring it, resolving it, or a missed call each write one,
     * that was most of a working inbox.
     */
    public function lastMessage()
    {
        return $this->hasOne(Message::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('message_type', '!=', MessageType::Info->value),
        );
    }

    /**
     * Fallback for a thread that holds nothing but notes — a missed call from a
     * number that never wrote opens one. `last_message` has never been null for
     * a conversation that has messages (MessageResource would break on it), and
     * a blank row is worse than the note that is genuinely all there is.
     */
    public function lastInfoMessage()
    {
        return $this->hasOne(Message::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('message_type', MessageType::Info->value),
        );
    }

    public function getLastMessageAttribute()
    {
        if ($this->relationLoaded('lastMessage')) {
            // getRelationValue, not getRelation: on the paths that eager-load
            // only the preview relation (a broadcast, an accept) the fallback
            // still resolves — and only for the rare row that needs it.
            return $this->getRelation('lastMessage') ?? $this->getRelationValue('lastInfoMessage');
        }

        return $this->messages()->where('message_type', '!=', MessageType::Info->value)
            ->latest('created_at')->latest('id')->first()
            ?? $this->messages()->where('message_type', MessageType::Info->value)
                ->latest('created_at')->latest('id')->first();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Contacts that have spoken in this conversation. Only meaningful for
     * group conversations; the group contact itself is never listed here.
     */
    public function participants()
    {
        return $this->belongsToMany(Contact::class, 'conversation_participants')->withTimestamps();
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /** Internal notes about this thread — never sent anywhere. */
    public function notes()
    {
        return $this->hasMany(ConversationNote::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'conversation_tags');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Stamp the closure. Kept here rather than at each call site so every path
     * that resolves a conversation (agent action, bulk update, expired window)
     * records the same thing, and so statistics can trust the column.
     */
    public function markResolved(?int $byUserId = null): void
    {
        $this->status = Status::Resolved;
        $this->resolved_at = now();
        $this->resolved_by_user_id = $byUserId;
        $this->save();
    }

    public function flowState()
    {
        return $this->hasOne(FlowState::class);
    }

    /**
     * Restrict a query to the conversations a user is allowed to *see*.
     *
     * This is the read-side counterpart of isAccessibleBy() (which answers for
     * a single, already-loaded row) and every list/lookup endpoint has to go
     * through it. Tenant scoping alone is not enough: agents are assigned
     * individual connections via connection_user, and until this existed an
     * agent with one connection could still pull the whole tenant's inbox from
     * GET /conversations.
     *
     * A null user (unauthenticated context) matches nothing rather than
     * everything — an empty result is a safe failure, a full one is not.
     */
    /**
     * visibleTo() plus exclusivity: the conversations whose *content* this
     * user may read. Every path that returns messages, notes or flow data
     * goes through here; the inbox list keeps using visibleTo(), because an
     * exclusive thread stays in everybody's list — only its content is hidden.
     */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        $query->visibleTo($user);

        if ($user && ! $user->canAccessAllConnections()) {
            $query->where(fn ($q) => $q
                ->whereNull('conversations.exclusive_at')
                ->orWhere('conversations.user_id', $user->id));
        }

        return $query;
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('connection', function ($q) use ($user) {
            $q->where('tenant_id', $user->tenant_id);

            // Owners hold no connection_user rows on purpose — see
            // User::canAccessAllConnections().
            if (! $user->canAccessAllConnections()) {
                $q->whereIn('connections.id', $user->accessibleConnectionIds());
            }
        });
    }

    /**
     * Whether the given user may read/act on this conversation.
     *
     * Rules (additive, but all gated on connection access first):
     * - Owner can access everything.
     * - Without access to the conversation's connection, nothing on it is
     *   reachable — not even a thread still assigned to this agent, which is
     *   what makes revoking a connection actually take effect.
     * - The assigned agent can access their own conversation.
     * - E-mail is a shared inbox: any agent with access to the e-mail
     *   connection can read and reply without the conversation being
     *   assigned to them (there is no accept/assign step for e-mail).
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->canAccessAllConnections()) {
            return true;
        }

        // NB: inside the model, $this->connection resolves Eloquent's internal
        // connection-name string ("mysql"), NOT the connection() relation —
        // go through the relation explicitly.
        $connection = $this->getRelationValue('connection');

        if (! $connection
            || (int) $connection->tenant_id !== (int) $user->tenant_id
            || ! $user->canAccessConnection($connection)) {
            return false;
        }

        if ($this->user_id !== null && (int) $this->user_id === (int) $user->id) {
            return true;
        }

        return $connection->channel === Channel::Email;
    }
}
