<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A role is the description of a job; a direct permission is an exception to it.
 *
 * The agents list could not tell them apart — it sent one flat `all_permissions`
 * array — so "what does this role give everyone" and "what did someone hand
 * this person on top" were the same column of chips. These two fields are the
 * split, and the count in the list reads from `extra_permissions`.
 */
function agentListUser(): User
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $owner->setRelation('tenant', $tenant);

    Sanctum::actingAs($owner);

    return $owner;
}

function makeAgent(int $tenantId, string $email): User
{
    return User::factory()->create(['tenant_id' => $tenantId, 'email' => $email]);
}

beforeEach(function () {
    foreach (['conversations.view', 'conversations.send', 'statistics.tenant.view', 'broadcasts.send'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('role permissions and extra permissions are reported separately', function () {
    $this->withoutMiddleware();
    $owner = agentListUser();

    $role = Role::findOrCreate('atendente', 'web');
    $role->givePermissionTo('conversations.view', 'conversations.send');

    $agent = makeAgent($owner->tenant_id, 'agent@example.com');
    $agent->assignRole($role);
    $agent->givePermissionTo('statistics.tenant.view');

    $row = collect($this->getJson('/api/agents')->assertOk()->json('data'))
        ->firstWhere('email', 'agent@example.com');

    expect($row['role_permissions'])->toBe(['conversations.send', 'conversations.view'])
        ->and($row['extra_permissions'])->toBe(['statistics.tenant.view'])
        // Still sent: the "can they do X" checks in the SPA read this one.
        ->and($row['all_permissions'])->toBe([
            'conversations.send', 'conversations.view', 'statistics.tenant.view',
        ]);
});

/**
 * ⚠️ The reason the count exists at all.
 *
 * The permissions dialog used to pre-tick everything the agent could do —
 * role-derived included — and saving calls syncPermissions, so one Save copied
 * the whole role into direct grants. Those copies are not exceptions: they
 * change nothing about what the agent can do, and counting them would report
 * "12 additional permissions" for an agent who has none.
 */
test('a direct grant the role already covers is not an extra', function () {
    $this->withoutMiddleware();
    $owner = agentListUser();

    $role = Role::findOrCreate('atendente', 'web');
    $role->givePermissionTo('conversations.view');

    $agent = makeAgent($owner->tenant_id, 'copied@example.com');
    $agent->assignRole($role);
    // The shape left behind by that bug: the role's own permission, pinned
    // directly as well.
    $agent->givePermissionTo('conversations.view');

    $row = collect($this->getJson('/api/agents')->assertOk()->json('data'))
        ->firstWhere('email', 'copied@example.com');

    expect($row['extra_permissions'])->toBe([])
        ->and($row['role_permissions'])->toBe(['conversations.view']);
});

test('an agent with no role reports every direct grant as extra', function () {
    $this->withoutMiddleware();
    $owner = agentListUser();

    $agent = makeAgent($owner->tenant_id, 'roleless@example.com');
    $agent->givePermissionTo('broadcasts.send');

    $row = collect($this->getJson('/api/agents')->assertOk()->json('data'))
        ->firstWhere('email', 'roleless@example.com');

    expect($row['role_permissions'])->toBe([])
        ->and($row['extra_permissions'])->toBe(['broadcasts.send']);
});

test('an agent on a role alone has no extras', function () {
    $this->withoutMiddleware();
    $owner = agentListUser();

    $role = Role::findOrCreate('atendente', 'web');
    $role->givePermissionTo('conversations.view', 'conversations.send');

    $agent = makeAgent($owner->tenant_id, 'clean@example.com');
    $agent->assignRole($role);

    $row = collect($this->getJson('/api/agents')->assertOk()->json('data'))
        ->firstWhere('email', 'clean@example.com');

    expect($row['extra_permissions'])->toBe([]);
});
