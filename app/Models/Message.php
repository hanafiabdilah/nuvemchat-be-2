<?php

namespace App\Models;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'external_id',
        'conversation_id',
        'sender_type',
        'sent_by_user_id',
        'sent_by_flow_id',
        'sent_by_ai_hub_agent_id',
        'message_type',
        'body',
        'attachment',
        'replied_message_id',
        'sent_at',
        'delivery_at',
        'read_at',
        'edited_at',
        'unsend_at',
        'meta',
        'error',
    ];

    protected $casts = [
        'sender_type' => SenderType::class,
        'message_type' => MessageType::class,
        'sent_at' => 'timestamp',
        'delivery_at' => 'timestamp',
        'read_at' => 'timestamp',
        'edited_at' => 'timestamp',
        'unsend_at' => 'timestamp',
        'meta' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($message) {
            $message->conversation->update([
                'last_message_at' => Carbon::createFromTimestamp($message->sent_at),
            ]);
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function repliedMessage()
    {
        return $this->belongsTo(Message::class, 'replied_message_id');
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function sentByUser()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function sentByFlow()
    {
        return $this->belongsTo(Flow::class, 'sent_by_flow_id');
    }

    public function sentByAiHubAgent()
    {
        return $this->belongsTo(AiHubAgent::class, 'sent_by_ai_hub_agent_id');
    }
}
