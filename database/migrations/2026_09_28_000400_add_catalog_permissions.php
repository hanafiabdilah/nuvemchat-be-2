<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `products.view`, `products.manage`, `orders.view` — the catalog and what the
 * AI sold from it.
 *
 * Two grants for products because they are two decisions: reading the
 * catalog is harmless, while editing it changes prices a bot is quoting to
 * customers right now. Orders are read-only in this release (they are written
 * by the AI and the gateway, never by a person), so one grant is enough.
 *
 * Declared in RoleAndPermissionSeeder too, but deploys only run
 * `migrate --force`, so existing owners get them here.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['products.view', 'products.manage', 'orders.view'];

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
