<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationTag extends Model
{
    protected $fillable = [
        'conversation_id',
        'tag_id',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }
}
