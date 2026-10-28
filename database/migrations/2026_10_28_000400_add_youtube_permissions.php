<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        // Connecting a channel hands the workspace a Google grant over it;
        // disconnecting revokes that grant. Neither is the same trust as
        // posting to it.
        'youtube-accounts.manage',
        'youtube-posts.view',
        'youtube-posts.create',
        'youtube-posts.publish',
        'youtube-posts.delete',
        'youtube-comments.manage',
    ];

    /**
     * Register the YouTube permissions and give them to owners.
     *
     * RoleAndPermissionSeeder declares them too, but deploys only run
     * `migrate --force`. Custom roles are left alone — who may post to the
     * company's channel is the workspace's call, the same as for Instagram.
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
