<?php

namespace App\Models;

use App\Enums\Broadcast\RecipientStatus;
use Illuminate\Database\Eloquent\Model;

class BroadcastRecipient extends Model
{
    protected $fillable = [
        'broadcast_id',
        'contact_id',
        'conversation_id',
        'message_id',
        'address',
        'name',
        'status',
        'error',
        'attempts',
        'sent_at',
    ];

    protected $casts = [
        'status' => RecipientStatus::class,
        'sent_at' => 'datetime',
    ];

    public function broadcast()
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The name to greet this recipient by: whatever the campaign was given, else
     * the contact's own, else the bare address. Never empty — a template that
     * renders "Olá ," reads worse than one that renders the number.
     */
    public function displayName(): string
    {
        return $this->name
            ?: $this->getRelationValue('contact')?->name
            ?: $this->address;
    }
}
