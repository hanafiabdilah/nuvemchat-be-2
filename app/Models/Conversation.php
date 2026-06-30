<?php

namespace App\Models;

use App\Enums\Conversation\Status;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'external_id',
        'connection_id',
        'user_id', // agent
        'status',
        'needs_human',
        'handoff_reason',
        'handoff_at',
        'last_message_at',
    ];

    protected $casts = [
        'status' => Status::class,
        'needs_human' => 'boolean',
        'handoff_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function getLastMessageAttribute()
    {
        return $this->messages()->latest('created_at')->latest('id')->first();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function connection()
    {
        return $this->belongsTo(Connection::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'conversation_tags');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flowState()
    {
        return $this->hasOne(FlowState::class);
    }
}
