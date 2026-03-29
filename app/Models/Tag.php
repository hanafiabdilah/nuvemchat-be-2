<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'color',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_tags');
    }
}
