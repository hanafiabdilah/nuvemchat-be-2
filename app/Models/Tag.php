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

    /**
     * The people carrying this tag permanently.
     *
     * The same tag row can be on both sides at once — "VIP" on a customer and
     * on the one thread where they said so — and that is the point: one
     * vocabulary, two places to hang it.
     */
    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'contact_tags');
    }
}
