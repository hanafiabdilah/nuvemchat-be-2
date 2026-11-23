<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSION = 'agents.deactivate';

    /**
     * Deactivating and reactivating an agent (someone who left the company).
     *
     * Handed to owner and to every role that already holds `agents.delete`:
     * whoever could already remove a person outright can do the gentler thing
     * too. Deploys only run `migrate --force`, so the seeder listing the same
     * permission would never reach an existing tenant on its own.
     */
    public function up(): void
    {
        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        // Absent on a database whose seeder has yet to run (a fresh install
        // migrates before it seeds), and asking a role about a permission that
        // does not exist throws rather than answering false.
        $removers = Permission::where('name', 'agents.delete')->where('guard_name', 'web')->first();

        Role::query()
            ->where('guard_name', 'web')
            ->where('is_platform', false)
            ->get()
            ->filter(fn (Role $role) => $role->name === 'owner'
                || ($removers !== null && $role->hasPermissionTo($removers)))
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
