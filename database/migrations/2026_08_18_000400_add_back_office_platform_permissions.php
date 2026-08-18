<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Platform permissions for the Back Office surfaces added in this release.
     *
     * PlatformRbacSeeder declares the same set, but deploys only run
     * `migrate --force` — without this the pages would ship invisible on every
     * existing install, including production.
     */
    private const PERMISSIONS = [
        'bo.ai-usage.view',
        // Pausing another company's live campaign is an intervention, not a
        // read, so it is not folded into an existing view permission.
        'bo.broadcasts.manage',
        'bo.health.view',
        'bo.storage.view',
        'bo.conversations.view',
        'bo.reports.export',
        // Granting a feature outside the plan bypasses billing; kept apart from
        // bo.subscriptions.manage so it can be handed out separately.
        'bo.entitlements.manage',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['is_platform' => true],
            );
        }

        // super-admin is defined as "every platform permission", so it has to be
        // resynced rather than left holding the old set.
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
