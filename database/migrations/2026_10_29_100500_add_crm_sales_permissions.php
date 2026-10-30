<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for leads, deals, tasks and logged activity.
 *
 * Working leads and deals, writing tasks and notes is what the people in the
 * inbox do all day, so viewing and managing them goes to every workspace role
 * and every operator with no role. Deleting and shaping the pipelines are the
 * owner's until the workspace hands them out. Declared in
 * RoleAndPermissionSeeder too; deploys only run `migrate --force`.
 */
return new class extends Migration
{
    private const EVERYONE = [
        'leads.view', 'leads.manage',
        'deals.view', 'deals.manage',
        'crm-tasks.manage', 'crm-activities.manage',
    ];

    private const OWNER = ['leads.delete', 'deals.delete', 'pipelines.manage'];

    public function up(): void
    {
        foreach ([...self::EVERYONE, ...self::OWNER] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()->where('guard_name', 'web')->where('is_platform', false)->get()
            ->each(function (Role $role) {
                $role->givePermissionTo(self::EVERYONE);

                if ($role->name === 'owner') {
                    $role->givePermissionTo(self::OWNER);
                }
            });

        User::query()
            ->whereNotNull('tenant_id')
            ->whereDoesntHave('roles')
            ->chunkById(200, fn ($users) => $users->each(fn (User $user) => $user->givePermissionTo(self::EVERYONE)));

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [...self::EVERYONE, ...self::OWNER])->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
