<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `conversations.transfer` and `conversations.take-over` — moving a thread to
 * someone else, and claiming one somebody else is holding.
 *
 * Until now neither had a permission at all: transfer was gated by being the
 * assignee, take-over by having access to the connection. Both rules stay —
 * the permission sits on top of them, so a workspace can now take either
 * action away from a role without taking away the inbox.
 *
 * Handed to every workspace role and to every attendant who holds no role:
 * on the day this ships everybody could already do both, and a button that
 * disappears from under an agent mid-shift reads as a bug, not as a policy
 * nobody chose. Restricting it is the workspace's decision to make afterwards.
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['conversations.transfer', 'conversations.take-over'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()
            ->where('guard_name', 'web')
            ->where('is_platform', false)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));

        // Attendants who were given direct permissions instead of a role have
        // nothing above to inherit from.
        User::query()
            ->whereNotNull('tenant_id')
            ->whereDoesntHave('roles')
            ->chunkById(200, fn ($users) => $users->each(
                fn (User $user) => $user->givePermissionTo(self::PERMISSIONS)
            ));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
