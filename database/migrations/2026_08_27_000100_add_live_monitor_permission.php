<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * The Back Office live monitor's permission.
     *
     * PlatformRbacSeeder declares it too, but deploys only run
     * `migrate --force` — without this the page would ship invisible on every
     * existing install, production included, with nothing in the UI to explain
     * why the sidebar entry never appeared.
     */
    private const PERMISSION = 'bo.live.view';

    public function up(): void
    {
        Permission::updateOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['is_platform' => true],
        );

        // super-admin is defined as "every platform permission", so it has to
        // be topped up rather than left holding the old set.
        Role::where('name', 'super-admin')->where('is_platform', true)->get()->each(
            fn (Role $role) => $role->givePermissionTo(self::PERMISSION)
        );

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
