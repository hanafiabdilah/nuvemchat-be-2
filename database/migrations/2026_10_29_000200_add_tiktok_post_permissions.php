<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'tiktok-posts.view',
        'tiktok-posts.create',
        'tiktok-posts.publish',
        'tiktok-posts.delete',
        'tiktok-comments.manage',
    ];

    /**
     * Register the TikTok publishing permissions and give them to owners —
     * RoleAndPermissionSeeder declares them too, but deploys only run
     * `migrate --force`. Custom roles are left to the workspace.
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
