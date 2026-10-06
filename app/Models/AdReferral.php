<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ad a conversation started from. See App\Services\Sales\AdReferrals.
 */
class AdReferral extends Model
{
    protected $fillable = [
        'tenant_id',
        'connection_id',
        'conversation_id',
        'contact_id',
        'platform',
        'ad_id',
        'source_type',
        'source_url',
        'title',
        'ctwa_clid',
        'referred_at',
    ];

    protected $casts = [
        'referred_at' => 'datetime',
    ];
}
