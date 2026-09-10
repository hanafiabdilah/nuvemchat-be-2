<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Tenant permissions for the Integrations page.
 *
 * Split the way the gallery pair is: `integrations.view` reads which apps are
 * connected and the payments they took; `integrations.manage` pastes API keys
 * that move the workspace's money or report to its ad accounts, and deletes
 * them. The second is the one that matters, which is why it is not implied by
 * `flows.update` — building a flow that charges customers and deciding which
 * account the money lands in are different decisions.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`: without this an existing owner would find no Integrations
 * entry after the release. Custom roles are left alone — who else may hold the
 * workspace's gateway keys is the tenant's call.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'integrations.view',
        'integrations.manage',
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
