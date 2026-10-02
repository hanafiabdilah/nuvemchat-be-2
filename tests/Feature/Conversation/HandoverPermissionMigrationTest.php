<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * Before this release every attendant could transfer and take over. The
 * migration must keep it that way until a workspace decides otherwise —
 * through a role, or directly for attendants who were given no role at all.
 */
test('existing roles and roleless attendants keep both actions', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $supervisor = Role::query()->create(['name' => 'Supervisor', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $platform = Role::query()->create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);

    $withRole = User::factory()->create(['tenant_id' => $tenant->id]);
    $withRole->assignRole($supervisor);
    $roleless = User::factory()->create(['tenant_id' => $tenant->id]);

    Permission::whereIn('name', ['conversations.transfer', 'conversations.take-over'])->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    (require database_path('migrations/2026_10_02_000100_add_conversation_handover_permissions.php'))->up();

    foreach ([$withRole, $roleless] as $user) {
        $user = $user->fresh();
        expect($user->hasPermissionTo('conversations.transfer'))->toBeTrue()
            ->and($user->hasPermissionTo('conversations.take-over'))->toBeTrue();
    }

    expect($platform->fresh()->hasPermissionTo('conversations.transfer'))->toBeFalse()
        // Granted through the role, not copied onto the person.
        ->and($withRole->fresh()->getDirectPermissions())->toBeEmpty();
});
