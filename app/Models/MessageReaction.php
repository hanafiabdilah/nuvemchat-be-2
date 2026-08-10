<?php

namespace App\Models;

use App\Enums\Message\SenderType;
use Illuminate\Database\Eloquent\Model;

class MessageReaction extends Model
{
    protected $fillable = [
        'message_id',
        'contact_id',
        'emoji',
        'sender_type',
    ];

    protected $casts = [
        'sender_type' => SenderType::class,
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Which group member reacted. Null in a private chat, where sender_type
     * already identifies the two possible reactors.
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
