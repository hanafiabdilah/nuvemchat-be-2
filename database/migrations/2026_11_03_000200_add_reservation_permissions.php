<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Three grants, because they are three decisions:
 *
 *  - `reservations.view` — read the calendar. What the person at the door
 *    needs. The owner's.
 *  - `reservations.manage` — write bookings down, change and call them off,
 *    and edit the services: their hours are what a bot is offering customers
 *    right now. The owner's.
 *  - `reservations.confirm` — answer a request from the conversation it came
 *    in (accept, decline, offer another time). The person answering the chat
 *    is the one who confirms, so every workspace role gets it, and every agent
 *    without a role — like the handover permissions.
 *
 * Declared in RoleAndPermissionSeeder too; deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const OWNER = ['reservations.view', 'reservations.manage'];

    private const EVERYONE = 'reservations.confirm';

    public function up(): void
    {
        foreach ([...self::OWNER, self::EVERYONE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()->where('guard_name', 'web')->where('is_platform', false)->get()
            ->each(function (Role $role) {
                $role->givePermissionTo(self::EVERYONE);

                if ($role->name === 'owner') {
                    $role->givePermissionTo(self::OWNER);
                }
            });

        // Agents given direct permissions instead of a role have nothing above
        // to inherit from.
        User::query()
            ->whereNotNull('tenant_id')
            ->whereDoesntHave('roles')
            ->chunkById(200, fn ($users) => $users->each(
                fn (User $user) => $user->givePermissionTo(self::EVERYONE)
            ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [...self::OWNER, self::EVERYONE])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
