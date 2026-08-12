<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'broadcasts.view',
        'broadcasts.create',
        'broadcasts.send',
        'broadcasts.delete',
    ];

    /**
     * Register the campaign permissions and give them to every owner.
     *
     * RoleAndPermissionSeeder declares the same four, but deploys only run
     * `migrate --force` — so without this, an existing tenant's owner would open
     * the dashboard after the release, see no Campaigns entry, and there would
     * be nothing in the UI to explain why. Custom roles are deliberately left
     * alone: who else may fire a blast at thousands of customers is the tenant's
     * decision, not a migration's.
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
