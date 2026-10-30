<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for CRM insights.
 *
 *  - `segments.manage`: shared customer segments — to every role that can
 *    already edit customers (a segment is a saved way of looking at them).
 *  - `crm-reports.view`: the reports show every operator's sales — the
 *    owner's until handed out.
 *  - `crm-data.export` / `crm-data.import`: customer data leaving or entering
 *    the workspace in bulk — the owner's until handed out. The customer list's
 *    "Export CSV" now asks for the export permission too.
 *
 * Declared in RoleAndPermissionSeeder too; deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const OWNER = ['segments.manage', 'crm-reports.view', 'crm-data.export', 'crm-data.import'];

    public function up(): void
    {
        foreach (self::OWNER as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $editors = Permission::query()->where('name', 'customers.update')->where('guard_name', 'web')->first();

        Role::query()->where('guard_name', 'web')->where('is_platform', false)->get()
            ->each(function (Role $role) use ($editors) {
                if ($role->name === 'owner') {
                    $role->givePermissionTo(self::OWNER);

                    return;
                }

                if ($editors && $role->hasPermissionTo($editors)) {
                    $role->givePermissionTo('segments.manage');
                }
            });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::OWNER)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
