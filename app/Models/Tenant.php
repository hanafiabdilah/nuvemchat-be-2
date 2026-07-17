<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Tenant extends Model
{
    protected $fillable = [
        'user_id',
        'current_subscription_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owner to reach for platform notifications, or null when there is no
     * verified WhatsApp number — legacy accounts predating verification and
     * Fortify-created users have none. Callers skip rather than fail.
     */
    public function notifiableOwner(): ?User
    {
        $user = $this->user;

        return $user && $user->whatsapp_verified_at && filled($user->whatsapp_number) ? $user : null;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The tenant's current subscription (denormalised pointer for O(1) lookup).
     */
    public function currentSubscription()
    {
        return $this->belongsTo(Subscription::class, 'current_subscription_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    public function connections()
    {
        return $this->hasMany(Connection::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * All conversations belonging to this tenant, reached through its
     * connections (conversations have no direct tenant_id).
     */
    public function conversations(): HasManyThrough
    {
        return $this->hasManyThrough(Conversation::class, Connection::class);
    }

    public function quickMessages()
    {
        return $this->hasMany(QuickMessage::class);
    }

    public function aiHubTenant()
    {
        return $this->hasOne(AiHubTenant::class);
    }
}
