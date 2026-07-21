<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Enums\Connection\SyncStatus;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    protected $fillable = [
        'tenant_id',
        'flow_id',
        'channel',
        'name',
        'color',
        'status',
        'credentials',
        'last_seen_uid',
        'last_synced_at',
        'sync_status',
        'sync_error',
        'sync_remaining',
        'sync_started_at',
        'api_key',
        'accept_message',
        'closing_message',
        'service_hours',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'status' => Status::class,
        'credentials' => 'array',
        'last_seen_uid' => 'integer',
        'last_synced_at' => 'datetime',
        'sync_status' => SyncStatus::class,
        'sync_remaining' => 'integer',
        'sync_started_at' => 'datetime',
        'service_hours' => 'array',
    ];

    /**
     * Get the tenant that owns the connection.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
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
