<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSION = 'agents.update-avatar';

    /**
     * Setting someone else's photo, as a permission of its own.
     *
     * Deliberately not folded into `agents.update`: that one carries the
     * e-mail and the password — the credentials somebody signs in with — and a
     * supervisor who should be able to put a face on a roster has no business
     * being handed the ability to change how a colleague logs in. Splitting it
     * is the only way a tenant can grant the small thing without the large one.
     *
     * Handed to every role that already holds `agents.update`, plus owner:
     * whoever could already edit an agent loses nothing on the day this ships,
     * and nobody has to go looking for a checkbox to explain a button that
     * disappeared. Deploys only run `migrate --force`, so the seeder listing the
     * same permission would never reach an existing tenant on its own.
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
