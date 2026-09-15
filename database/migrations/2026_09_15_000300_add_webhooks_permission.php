<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `webhooks.manage` — point the workspace's lead events at an outside system.
 *
 * Its own permission rather than riding on `api-keys.manage`: a webhook sends
 * customer names, phone numbers and sale values to whatever URL is typed in,
 * which is a decision about where the workspace's data goes.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`, so existing owners get it here.
 */
return new class extends Migration
{
    private const PERMISSION = 'webhooks.manage';

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
