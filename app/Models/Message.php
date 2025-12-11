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
        'attachment',
        'sent_at',
        'meta',
    ];

    protected $casts = [
        'sender_type' => SenderType::class,
        'message_type' => MessageType::class,
        'sent_at' => 'timestamp',
        'meta' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($message) {
            $message->conversation->update([
                'last_message_at' => $message->sent_at,
            ]);
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
