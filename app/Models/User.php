<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'whatsapp_number',
        'whatsapp_verified_at',
        'ui_preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'ui_preferences' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Whether this agent's dashboard has reported in recently enough to treat
     * them as available for work being routed to them automatically.
     *
     * Never-seen reads as offline, which is the safe direction: the only
     * caller (see LastAgentRouter) falls back to the normal queue, so a
     * deployment whose frontend has not shipped the heartbeat yet keeps
     * behaving exactly as it did before.
     */
    public function isOnline(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->gt(now()->subSeconds((int) config('presence.online_seconds', 150)));
    }

    /**
     * Record a heartbeat. Written without touching `updated_at`: presence is
     * not a change to the user, and moving `updated_at` once a minute per
     * signed-in agent would make that column useless for anything else.
     */
    public function markSeen(): void
    {
        static::withoutTimestamps(function () {
            $this->forceFill(['last_seen_at' => now()])->saveQuietly();
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the connections that the user has access to (for agents).
     */
    public function connections()
    {
        return $this->belongsToMany(Connection::class)->withTimestamps();
    }

    /**
     * Memoised result of accessibleConnectionIds(). Deliberately NOT named
     * $connections (that would collide with the relation) and NOT named
     * $connection (Eloquent reserves that for the DB connection name).
     *
     * @var array<int, int>|null
     */
    protected ?array $accessibleConnectionIdsCache = null;

    /**
     * Owners see every connection of their tenant, including ones created after
     * they were made owner — which is why they are never given connection_user
     * rows and must be answered by a role check rather than by the pivot.
     */
    public function canAccessAllConnections(): bool
    {
        return $this->hasRole('owner');
    }

    /**
     * The connection ids this user may read. Meaningless for owners — callers
     * must check canAccessAllConnections() first, since an owner's pivot is
     * legitimately empty.
     *
     * @return array<int, int>
     */
    public function accessibleConnectionIds(): array
    {
        return $this->accessibleConnectionIdsCache ??= $this->connections()
            ->where('connections.tenant_id', $this->tenant_id)
            ->pluck('connections.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether this user may read/act on the given connection.
     *
     * Accepts an id so callers that only have a foreign key don't have to load
     * the model just to ask.
     */
    public function canAccessConnection(Connection|int|string|null $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        $id = $connection instanceof Connection ? $connection->id : $connection;

        if ($connection instanceof Connection && (int) $connection->tenant_id !== (int) $this->tenant_id) {
            return false;
        }

        if ($this->canAccessAllConnections()) {
            // Still tenant-scoped: an owner of tenant A is not an owner of B.
            // For a bare id the caller is responsible for the tenant check —
            // every call site here resolves the connection tenant-scoped first.
            return true;
        }

        return in_array((int) $id, $this->accessibleConnectionIds(), true);
    }

    /**
     * Get the user's quick messages.
     */
    public function quickMessages()
    {
        return $this->hasMany(QuickMessage::class);
    }
}
