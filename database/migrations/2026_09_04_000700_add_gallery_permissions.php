<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Tenant permissions for the media gallery.
 *
 * Two, split the same way the virtual-number pair is: `gallery.view` is reading
 * the library and picking a file to send — something every agent needs all day.
 * `gallery.manage` uploads, renames, deletes, and rents storage, which spends
 * the prepaid balance every month and can destroy a file other people's
 * messages point at.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`: without this an existing owner would open the dashboard
 * after the release and find no Gallery entry, with nothing to explain why.
 * Custom roles are left alone — who else may spend the balance is the tenant's
 * call, not a migration's.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'gallery.view',
        'gallery.manage',
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
