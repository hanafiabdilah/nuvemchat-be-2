<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LiveChatSession extends Model
{
    protected $fillable = [
        'session_token',
        'connection_id',
        'contact_id',
        'conversation_id',
        'visitor_name',
        'visitor_email',
        'visitor_ip',
        'page_url',
        'user_agent',
        'meta',
        'last_seen_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_seen_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $session) {
            if (empty($session->session_token)) {
                $session->session_token = (string) Str::uuid();
            }
        });
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
