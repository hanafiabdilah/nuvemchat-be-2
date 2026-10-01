<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone of the mobile app, able to receive push notifications for its user.
 * Written only through DeviceTokens::register(); see the migration for why the
 * row hangs off the login session.
 */
class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'personal_access_token_id',
        'device_id',
        'token',
        'platform',
        'app_version',
        'locale',
        'last_registered_at',
        'last_sent_at',
        'last_failed_at',
        'last_error',
    ];

    /** The FCM token is an address to this phone; it never goes back out. */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_registered_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
