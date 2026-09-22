<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Enums\Connection\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Connection extends Model
{
    public const PUBLIC_ID_PREFIX = 'conn_';

    protected $fillable = [
        'tenant_id',
        'flow_id',
        'channel',
        'name',
        'color',
        'status',
        'credentials',
        'last_seen_uid',
        'sync_window_days',
        'backfill_uid',
        'backfill_done',
        'last_synced_at',
        'sync_status',
        'sync_error',
        'sync_remaining',
        'sync_started_at',
        'accept_message',
        'closing_message',
        'return_to_last_agent',
        'return_to_last_agent_minutes',
        'service_hours',
        'ai_suggest_agent_id',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'status' => Status::class,
        // Not 'array', and not 'encrypted:array' either — the secret
        // values inside are encrypted while the identity keys stay queryable.
        // App\Services\Connection\ConnectionCredentials explains why.
        'credentials' => \App\Casts\EncryptedCredentials::class,
        'last_seen_uid' => 'integer',
        'sync_window_days' => 'integer',
        'backfill_uid' => 'integer',
        'backfill_done' => 'boolean',
        'last_synced_at' => 'datetime',
        'sync_status' => SyncStatus::class,
        'sync_remaining' => 'integer',
        'sync_started_at' => 'datetime',
        'service_hours' => 'array',
        'return_to_last_agent' => 'boolean',
        'return_to_last_agent_minutes' => 'integer',
    ];

    /**
     * `public_id` is the id people and integrations see — the Connection ID in
     * the dashboard and `connection_id` in the public API. Never the
     * auto-increment: that one counts every workspace's connections, so it
     * leaks how many exist and invites guessing a neighbour's. Assigned on
     * create, which covers every path that makes a connection (the wizard,
     * duplicate, seeders), and never mass-assignable.
     */
    protected static function booted(): void
    {
        static::creating(function (Connection $connection) {
            $connection->public_id ??= self::newPublicId();
        });
    }

    public static function newPublicId(): string
    {
        do {
            $id = self::PUBLIC_ID_PREFIX.Str::lower(Str::random(16));
        } while (self::where('public_id', $id)->exists());

        return $id;
    }

    /**
     * How long after a conversation closes a returning contact still counts as
     * the same visit. Clamped rather than trusted: an old row created before
     * the column existed reads as 0, and a 0-minute tolerance would silently
     * turn the switch into a no-op that looks enabled.
     */
    public function returnToLastAgentMinutes(): int
    {
        return max(1, (int) ($this->return_to_last_agent_minutes ?: 15));
    }

    /**
     * Get the tenant that owns the connection.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The "Respond with AI" agent linked to this connection (null = feature
     * off). Points at the AI Hub agents — the same agents flow AIAgent nodes
     * use — so one trained agent serves both features.
     */
    public function aiSuggestAgent()
    {
        return $this->belongsTo(AiHubAgent::class, 'ai_suggest_agent_id');
    }

    /**
     * Get the users (agents) that have access to this connection.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Get the conversations for this connection.
     */
    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the flow associated with this connection.
     */
    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }
}
