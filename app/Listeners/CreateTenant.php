<?php

namespace App\Listeners;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Give a newly registered owner the workspace they just signed up for.
 *
 * Only reached from the Inertia starter-kit's `/register` (Fortify fires
 * `Registered`; the product's own `POST /api/auth/register` creates the tenant
 * itself, inside a transaction, and fires nothing).
 *
 * ⚠️ This used to be `$user->tenant()->create([])`, and it could never have
 * worked: `tenant()` is a **belongsTo**, so the foreign key lives on
 * `users.tenant_id`, not on the tenant. `create()` on that relation inserts a
 * tenant with no `user_id`, which the column refuses — so every registration
 * through that route created the user, assigned them the owner role, and then
 * threw, leaving an account with no workspace behind. Fortify fires this event
 * outside any transaction, so the half-made user was already committed.
 *
 * Harmless in production only by accident: Caddy does not route `/register` to
 * Laravel (it falls through to the SPA), so nobody could reach it. The failing
 * test was the only thing still pointing at it.
 */
class CreateTenant
{
    public function handle(Registered $event): void
    {
        $user = User::find($event->user->id);

        if (! $user?->hasRole('owner') || $user->tenant_id) {
            return;
        }

        // Both writes or neither: a tenant nobody points at is invisible, and a
        // user pointing at a tenant that failed to save is worse.
        DB::transaction(function () use ($user) {
            $tenant = Tenant::create(['user_id' => $user->id]);

            $user->tenant_id = $tenant->id;
            $user->save();
        });
    }
}
