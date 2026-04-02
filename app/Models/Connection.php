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
    ];

    protected $casts = [
        'channel' => Channel::class,
        'stauts' => Status::class,
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
}
