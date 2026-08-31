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
        'bo.ai-usage.view',
        // The trained-agent catalog: categories, blueprints and their prices.
        // Separate from bo.plans.manage because it is content authoring as much
        // as pricing — the person writing a medical-office prompt is not
        // necessarily the person allowed to reprice the platform's plans.
        'bo.trained-agents.manage',
        // Pausing another company's live campaign is an intervention, not a
        // read, so it is not folded into an existing view permission.
        'bo.broadcasts.manage',
        'bo.health.view',
        'bo.storage.view',
        'bo.conversations.view',
        // The realtime wallboard. Metadata only, like bo.conversations.view,
        // but kept separate so a NOC screen can be handed out without the
        // per-customer volume reporting alongside it.
        'bo.live.view',
        'bo.reports.export',
        // Granting a feature outside the plan bypasses billing; kept apart from
        // bo.subscriptions.manage so it can be handed out separately.
        'bo.entitlements.manage',
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
