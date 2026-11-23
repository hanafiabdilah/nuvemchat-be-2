<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSION = 'agents.set-offline';

    /**
     * Turning a colleague's switch to Offline, from the agent control page.
     *
     * Its own permission rather than part of `agents.update`: that one carries
     * the e-mail and the password, and the person running the shift — who
     * needs to take somebody who went home out of automatic routing — has no
     * business being handed how a colleague signs in.
     *
     * Handed to owner and to every role that already holds `agents.update`:
     * whoever could already manage the team can run its day, and nobody has to
     * go looking for a checkbox. Deploys only run `migrate --force`, so the
     * seeder listing the same permission would never reach an existing tenant
     * on its own.
     */
    public function up(): void
    {
        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        // `agents.update` is absent on a database whose seeder has yet to run
        // (a fresh install migrates before it seeds), and asking a role about a
        // permission that does not exist throws rather than answering false.
        $editors = Permission::where('name', 'agents.update')->where('guard_name', 'web')->first();

        Role::query()
            ->where('guard_name', 'web')
            ->where('is_platform', false)
            ->get()
            ->filter(fn (Role $role) => $role->name === 'owner'
                || ($editors !== null && $role->hasPermissionTo($editors)))
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
