<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Back Office permission for coupons. Its own permission: a coupon is money
 * given away on every charge it touches, so creating one is not folded into
 * editing plans. Declared in PlatformRbacSeeder as well; deploys only run
 * `migrate --force`, so without this the page ships invisible.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'bo.coupons.manage',
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
