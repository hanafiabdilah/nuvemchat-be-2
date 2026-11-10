<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `calls.answer` — being rung for, and answering, WhatsApp calls.
 *
 * Handed to every workspace role and to every attendant without a role, the
 * same way `conversations.manage-flow` was: calls ring everyone who can reach
 * the connection, and a workspace that wants fewer people on the phone takes
 * the permission away from a role afterwards. Declared in
 * RoleAndPermissionSeeder too, but deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const PERMISSION = 'calls.answer';

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
