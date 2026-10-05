<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `conversations.manage-flow` — starting a flow in a conversation by hand, and
 * pausing or resuming the one that is running.
 *
 * Handed to every workspace role and to every attendant who holds no role, the
 * same way `conversations.transfer` was: the buttons are for the people who
 * answer customers, and a workspace that wants fewer hands on them takes the
 * permission away from a role afterwards. Declared in RoleAndPermissionSeeder
 * too, but deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const PERMISSION = 'conversations.manage-flow';

    public function up(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');

        Role::query()
            ->where('guard_name', 'web')
            ->where('is_platform', false)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSION));

        User::query()
            ->whereNotNull('tenant_id')
            ->whereDoesntHave('roles')
            ->chunkById(200, fn ($users) => $users->each(
                fn (User $user) => $user->givePermissionTo(self::PERMISSION)
            ));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
