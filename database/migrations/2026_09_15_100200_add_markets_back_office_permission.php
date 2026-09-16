<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Back Office permission for markets: opening a country, and deciding which
 * domains belong to it.
 *
 * Its own permission: a market's currency is what every workspace in it is
 * billed in, for good, and a domain added here changes which market new
 * signups on it join. Declared in PlatformRbacSeeder as well; deploys only run
 * `migrate --force`, so without this the page ships invisible.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'bo.markets.manage',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['is_platform' => true],
            );
        }

        Role::where('name', 'super-admin')->where('is_platform', true)->get()->each(
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
