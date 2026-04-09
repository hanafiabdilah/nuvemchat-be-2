<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    protected $fillable = [
        'tenant_id',
        'channel',
        'name',
        'color',
        'status',
        'credentials',
        'api_key',
        'welcoming_message',
        'accept_message',
        'closing_message',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'status' => Status::class,
        'credentials' => 'array',
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
}
