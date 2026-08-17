<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'leads.view',
        'leads.create',
        'leads.update',
        'leads.delete',
        // Changing the shape of the funnel itself — how many stages there are
        // and what counts as won — is a different decision from working a card,
        // so it is a separate permission rather than part of leads.update.
        'lead-pipelines.manage',
    ];

    /**
     * Register the lead permissions and give them to every existing owner.
     *
     * RoleAndPermissionSeeder declares the same set for new tenants, but deploys
     * only run `migrate --force` — so without this an existing workspace would
     * ship the release with no Leads entry in the menu and nothing on screen to
     * explain why. Custom roles are left alone on purpose: who may work the
     * funnel is the tenant's call, not a migration's.
     */
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
