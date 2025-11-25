<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    protected $fillable = [
        'user_id',
        'channel',
        'name',
        'color',
        'status',
        'credentials',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'stauts' => Status::class,
        'credentials' => 'array',
    ];
}
