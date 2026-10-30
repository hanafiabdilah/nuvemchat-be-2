<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for the CRM's people and companies.
 *
 * Handed out so nobody loses anything on deploy day: the contact book these
 * pages replace was open to every member, so `customers.view` and
 * `companies.view` go to every workspace role and to every operator with no
 * role; editing goes wherever `contacts.update` already was, since that is
 * what edited a contact before. Merging, deleting and the workspace's custom
 * fields are owner-only until a workspace hands them out.
 *
 * Declared in RoleAndPermissionSeeder too; deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const VIEW = ['customers.view', 'companies.view'];

    private const EDIT = ['customers.update', 'companies.manage'];

    private const OWNER = ['customers.merge', 'customers.delete', 'crm-settings.manage'];

    public function up(): void
    {
        foreach ([...self::VIEW, ...self::EDIT, ...self::OWNER] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Absent on a fresh install that has not been seeded yet.
        $contactsUpdate = Permission::query()->where('name', 'contacts.update')->where('guard_name', 'web')->first();

        $roles = Role::query()->where('guard_name', 'web')->where('is_platform', false)->get();

        foreach ($roles as $role) {
            $role->givePermissionTo(self::VIEW);

            if ($role->name === 'owner' || ($contactsUpdate && $role->hasPermissionTo($contactsUpdate))) {
                $role->givePermissionTo(self::EDIT);
            }

            if ($role->name === 'owner') {
                $role->givePermissionTo(self::OWNER);
            }
        }

        // Operators given direct permissions instead of a role.
        User::query()
            ->whereNotNull('tenant_id')
            ->whereDoesntHave('roles')
            ->chunkById(200, fn ($users) => $users->each(function (User $user) use ($contactsUpdate) {
                $user->givePermissionTo(self::VIEW);

                if ($contactsUpdate && $user->hasDirectPermission($contactsUpdate)) {
                    $user->givePermissionTo(self::EDIT);
                }
            }));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [...self::VIEW, ...self::EDIT, ...self::OWNER])->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
