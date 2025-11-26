<?php

namespace App\Models;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'external_id',
        'conversation_id',
        'sender_type',
        'message_type',
        'body',
        'sent_at',
        'meta',
    ];

    protected $casts = [
        'sender_type' => SenderType::class,
        'message_type' => MessageType::class,
        'sent_at' => 'timestamp',
        'meta' => 'array',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
