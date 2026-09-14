<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `api-keys.manage` — create and revoke workspace API keys.
 *
 * Separate from `connections.generate-api-key`: that key sends through one
 * connection, while a workspace key opens conversations on any of them and
 * writes to the sales board. Handing out the second is a bigger decision.
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
