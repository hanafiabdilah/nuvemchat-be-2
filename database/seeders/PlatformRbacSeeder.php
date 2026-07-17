<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PlatformRbacSeeder extends Seeder
{
    /**
     * Platform (Back Office) permissions — namespaced `bo.*` and flagged
     * is_platform so they never mix with tenant RBAC on the shared web guard.
     */
    public const PERMISSIONS = [
        'bo.dashboard.view',
        'bo.customers.view',
        'bo.users.view',
        'bo.connections.view',
        'bo.statistics.view',
        'bo.impersonate',
        'bo.audit.view',
        'bo.logs.view',
        'bo.admins.manage',
        'bo.roles.manage',
        'bo.plans.manage',
        'bo.subscriptions.manage',
        // Invoices and Payments are two lenses on the same table, so one permission.
        'bo.invoices.view',
        'bo.revenue.view',
        'bo.settings.manage',
    ];

    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['is_platform' => true],
            );
        }

        // super-admin is the built-in platform role: full access, undeletable.
        $superAdmin = Role::updateOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['is_platform' => true],
        );
        $superAdmin->syncPermissions(Permission::where('is_platform', true)->get());

        $this->command->info('Platform RBAC seeded.');
    }
}
