<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const PERMISSIONS = [
        'instagram-posts.view',
        'instagram-posts.create',
        'instagram-posts.publish',
        'instagram-posts.delete',
        'instagram-comments.manage',
    ];

    /**
     * Register the Instagram publishing permissions and give them to owners.
     *
     * Same reasoning as the campaign permissions: RoleAndPermissionSeeder
     * declares these too, but deploys only run `migrate --force`, so without
     * this an existing tenant's owner would never see the Instagram entry
     * appear and nothing in the UI would explain the absence.
     *
     * `view` is the one the nav entry keys on. Custom roles are left alone —
     * who may post to the company's Instagram account is the tenant's call.
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
