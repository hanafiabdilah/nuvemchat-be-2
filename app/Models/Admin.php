<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A Back Office (platform) admin.
 *
 * Deliberately not a `User`. Admins and customers' users are different
 * populations that happen to both sign in: an admin has no tenant, no
 * connections, no conversations and no billing, while a user has nothing but
 * those. Sharing one table meant sharing one unique index on `email`, so an
 * operator's own address was permanently unavailable to the business they
 * work for — and the rejection said only "email already taken", pointing at a
 * row nobody involved could see.
 *
 * What it keeps from the old arrangement, on purpose:
 *
 * - `guard_name = 'web'`, so the platform roles and `bo.*` permissions already
 *   in the database keep applying without a guard migration. Spatie resolves
 *   the guard from the auth providers otherwise, and there is no provider for
 *   this model — nor should there be, since the Back Office authenticates by
 *   Sanctum token only.
 * - Sanctum's `tokenable` is a morph, so admin tokens work unchanged; the only
 *   difference is the type they store.
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\AdminFactory> */
    use HasApiTokens, HasFactory, HasRoles;

    /**
     * Platform roles/permissions were seeded on the `web` guard and are shared
     * with nothing else (they carry `is_platform`), so this stays 'web' rather
     * than introducing a second guard for one model.
     */
    protected string $guard_name = 'web';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether this account may use the Back Office at all.
     *
     * An admin row with no platform role is an account that exists but grants
     * nothing — which is what a role deleted out from under someone leaves
     * behind. Treated as "no access" rather than as an error, so the answer is
     * the same at login and on every request afterwards.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->roles()->where('is_platform', true)->exists();
    }
}
