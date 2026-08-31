<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Back Office permissions for the rented-token pool and the credit wallets.
 *
 * Declared in PlatformRbacSeeder too, but deploys only run `migrate --force` —
 * without this the pages would ship invisible on every existing install.
 *
 * Two permissions, not one: holding the platform's provider secrets and topping
 * up a customer's balance are different jobs. Someone in support should be able
 * to comp credit after an outage without also being able to read, replace or
 * revoke the API keys the whole platform runs on.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'bo.ai-tokens.manage',
        'bo.ai-credits.manage',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['is_platform' => true],
            );
        }

        // super-admin is defined as "every platform permission", so it has to
        // be topped up rather than left holding the old set.
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
