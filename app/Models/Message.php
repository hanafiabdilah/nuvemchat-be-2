<?php

namespace App\Models;

use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Services\Broadcast\OptOutDetector;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'external_id',
        'conversation_id',
        'contact_id', // sender contact (group conversations)
        'sender_type',
        'sent_by_user_id',
        'sent_by_flow_id',
        'sent_by_ai_hub_agent_id',
        'message_type',
        'body',
        'attachment',
        'attachment_status',
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
        'attachment_status' => AttachmentStatus::class,
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

            // A customer replying "PARAR" is the only opt-out signal WhatsApp
            // gives us — there is no unsubscribe webhook to receive — so it has
            // to be read off the message itself. Cheap: anything that is not a
            // plain inbound text returns on the first comparison.
            OptOutDetector::noteInboundMessage($message);
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The contact that sent this message. Filled for incoming messages in
     * group conversations, where the sender varies per message.
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
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
