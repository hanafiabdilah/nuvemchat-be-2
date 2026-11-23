<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `incidents.view` reads the incident page — what was sent, to whom, and the
 * first messages that did not match; `incidents.manage` turns an incident on,
 * which makes the platform answer every customer on the chosen connections.
 *
 * Owner only. The banner that tells every attendant an incident is running
 * needs neither permission: knowing why the inbox is answering itself is not a
 * privilege. Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`. Who else may switch one on is the workspace's call.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'incidents.view',
        'incidents.manage',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::where('name', 'owner')->get()->each(
            fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS)
        );

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
