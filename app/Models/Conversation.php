<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'external_id',
        'connection_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'timestamp',
    ];

    public function getLastMessageAttribute()
    {
        return $this->messages()->latest('sent_at')->first();
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
