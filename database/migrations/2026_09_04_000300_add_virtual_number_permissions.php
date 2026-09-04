<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Tenant permissions for renting virtual numbers.
 *
 * Two, split on what the action costs. `numbers.view` is reading a number and
 * the codes that arrive on it — sensitive, because an OTP is a credential, but
 * free. `numbers.manage` spends the prepaid balance and cancels a subscription
 * somebody may still be waiting on.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`: without this an existing owner would open the dashboard
 * after the release and find no Números entry, with nothing to explain why.
 * Custom roles are left alone — who else may spend the balance is the tenant's
 * call, not a migration's.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'numbers.view',
        'numbers.manage',
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
