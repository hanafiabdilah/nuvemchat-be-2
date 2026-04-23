<?php

namespace App\Models;

use App\Enums\Message\SenderType;
use Illuminate\Database\Eloquent\Model;

class MessageReaction extends Model
{
    protected $fillable = [
        'message_id',
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
}
