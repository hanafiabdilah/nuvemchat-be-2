<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The migration that gave roles a workspace decides, for every role that
 * existed before, whose it is — from who holds it. By the time a test has rows
 * to seed, the migration has already run on an empty database, so its backfill
 * is run again here against the shape production had.
 */
function roleBackfillMigration(): object
{
    return require database_path('migrations/2026_09_11_100100_a_scope_roles_per_tenant.php');
}

function roleBackfillTenantUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

/** A role as it existed before: no workspace. */
function roleBackfillLegacy(string $name): Role
{
    return Role::query()->create(['name' => $name, 'guard_name' => 'web']);
}

test('a role held in several workspaces is copied so each has its own', function () {
    $ana = roleBackfillTenantUser();
    $bruno = roleBackfillTenantUser();

    $supervisor = roleBackfillLegacy('Supervisor');
    $supervisor->givePermissionTo(Permission::findOrCreate('leads.view', 'web'));
    $ana->assignRole($supervisor);
    $bruno->assignRole($supervisor);

    roleBackfillMigration()->backfill();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $roles = Role::where('name', 'Supervisor')->orderBy('id')->get();
    expect($roles)->toHaveCount(2)
        ->and($roles->pluck('tenant_id')->all())->toBe([$ana->tenant_id, $bruno->tenant_id]);

    $anasRole = $ana->fresh()->roles->sole();
    $brunosRole = $bruno->fresh()->roles->sole();

    // The first workspace keeps the original row; the other gets a copy with
    // the same permissions, so nobody's access changes.
    expect($anasRole->id)->toBe($supervisor->id)
        ->and($brunosRole->id)->not->toBe($supervisor->id)
        ->and($brunosRole->tenant_id)->toBe($bruno->tenant_id)
        ->and($brunosRole->permissions->pluck('name')->all())->toBe(['leads.view'])
        ->and($bruno->fresh()->hasPermissionTo('leads.view'))->toBeTrue();
});

test('a role held in one workspace becomes that workspace\'s', function () {
    $ana = roleBackfillTenantUser();
    roleBackfillTenantUser();

    $sales = roleBackfillLegacy('Vendas');
    $ana->assignRole($sales);

    roleBackfillMigration()->backfill();

    expect($sales->fresh()->tenant_id)->toBe($ana->tenant_id)
        ->and(Role::where('name', 'Vendas')->count())->toBe(1);
});

test('a role nobody holds stays global and hidden when there are several workspaces', function () {
    roleBackfillTenantUser();
    roleBackfillTenantUser();
    $orphan = roleBackfillLegacy('Estagiário');

    roleBackfillMigration()->backfill();

    expect($orphan->fresh()->tenant_id)->toBeNull();
});

test('on a single-workspace install a role nobody holds belongs to that workspace', function () {
    $ana = roleBackfillTenantUser();
    $orphan = roleBackfillLegacy('Estagiário');

    roleBackfillMigration()->backfill();

    expect($orphan->fresh()->tenant_id)->toBe($ana->tenant_id);
});

test('the owner role and platform roles stay global', function () {
    $ana = roleBackfillTenantUser();
    $owner = Role::findOrCreate('owner', 'web');
    $ana->assignRole($owner);
    $platform = Role::create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);

    roleBackfillMigration()->backfill();

    expect($owner->fresh()->tenant_id)->toBeNull()
        ->and($platform->fresh()->tenant_id)->toBeNull();
});

test('back office permissions are flagged and taken off workspace roles and users', function () {
    $ana = roleBackfillTenantUser();
    $owner = Role::findOrCreate('owner', 'web');

    // A `bo.*` name no migration creates, so it starts without the flag.
    $flagless = Permission::create(['name' => 'bo.flagless.view', 'guard_name' => 'web']);
    $leads = Permission::findOrCreate('leads.view', 'web');
    $owner->givePermissionTo($flagless, $leads);
    $ana->givePermissionTo($flagless);

    $superAdmin = Role::query()->create(['name' => 'super-admin', 'guard_name' => 'web']);

    roleBackfillMigration()->flagPlatformRecords();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect((bool) $flagless->fresh()->is_platform)->toBeTrue()
        ->and((bool) $superAdmin->fresh()->is_platform)->toBeTrue()
        ->and($owner->fresh()->permissions->pluck('name')->all())->toBe(['leads.view'])
        ->and(DB::table('model_has_permissions')->where('model_id', $ana->id)->count())->toBe(0);
});
