<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `crm-automations.manage`: create, change and read the history of CRM
 * automations. An automation acts on everyone's leads and deals without
 * anybody pressing a button, so it starts as the owner's — plus any role
 * already trusted with the CRM settings it is built from.
 *
 * Declared in RoleAndPermissionSeeder too; deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const PERMISSION = 'crm-automations.manage';

    public function up(): void
    {
        Permission::findOrCreate(self::PERMISSION, 'web');

        $settings = Permission::query()->where('name', 'crm-settings.manage')->where('guard_name', 'web')->first();

        Role::query()->where('guard_name', 'web')->where('is_platform', false)->get()
            ->each(function (Role $role) use ($settings) {
                if ($role->name === 'owner' || ($settings && $role->hasPermissionTo($settings))) {
                    $role->givePermissionTo(self::PERMISSION);
                }
            });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
