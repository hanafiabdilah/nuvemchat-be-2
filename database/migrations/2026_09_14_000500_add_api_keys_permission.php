<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `api-keys.manage` — create and revoke API keys.
 *
 * An API key acts for the whole workspace: it sends through any connection,
 * opens conversations and writes to the sales board. That is a decision about
 * the workspace, not about one connection, so it is its own permission.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`, so existing owners get it here.
 */
return new class extends Migration
{
    private const PERMISSION = 'api-keys.manage';

    public function up(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');

        Role::where('name', 'owner')->get()->each(
            fn (Role $role) => $role->givePermissionTo(self::PERMISSION)
        );

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
