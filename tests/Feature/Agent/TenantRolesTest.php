<?php

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Roles belong to one workspace (roles.tenant_id).
 *
 * They used to be one platform-wide list: every workspace saw — and could hand
 * out — every other workspace's roles, and a name used by one workspace could
 * not be used by any other.
 */
const TENANT_ROLE_PERMISSIONS = [
    'roles.view', 'roles.create', 'roles.update', 'roles.delete',
    'agents.view', 'agents.create', 'agents.assign-roles', 'agents.assign-permissions',
    'leads.view', 'contacts.update',
];

function roleScopeOwner(string $name = 'Dona'): User
{
    $owner = User::factory()->create(['name' => $name]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (TENANT_ROLE_PERMISSIONS as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $owner->assignRole($role);

    return $owner->fresh();
}

function roleScopeAgent(User $owner, string $email): User
{
    return User::factory()->create(['tenant_id' => $owner->tenant_id, 'email' => $email]);
}

function roleScopeCreate(User $owner, string $name, array $permissions = ['leads.view']): Role
{
    test()->actingAs($owner, 'sanctum')
        ->postJson('/api/roles', ['name' => $name, 'permissions' => $permissions])
        ->assertCreated();

    return Role::where('name', $name)->where('tenant_id', $owner->tenant_id)->sole();
}

test('two workspaces can each have a role with the same name', function () {
    $a = roleScopeOwner('A');
    $b = roleScopeOwner('B');

    $roleA = roleScopeCreate($a, 'Supervisor');
    $roleB = roleScopeCreate($b, 'Supervisor', ['contacts.update']);

    expect($roleA->id)->not->toBe($roleB->id)
        ->and($roleA->permissions->pluck('name')->all())->toBe(['leads.view'])
        ->and($roleB->permissions->pluck('name')->all())->toBe(['contacts.update']);

    // …but not twice in the same one.
    $this->actingAs($a, 'sanctum')
        ->postJson('/api/roles', ['name' => 'Supervisor'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A role with this name already exists.');
});

test('a workspace lists its own roles and the owner role, nothing else', function () {
    $a = roleScopeOwner('A');
    $b = roleScopeOwner('B');

    roleScopeCreate($a, 'Supervisor');
    roleScopeCreate($b, 'Vendas');
    Role::create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);
    // A legacy global role nobody holds (the migration leaves those hidden).
    Role::query()->create(['name' => 'Esquecida', 'guard_name' => 'web']);

    $roles = collect($this->actingAs($a, 'sanctum')->getJson('/api/roles')->assertOk()->json('data'));

    expect($roles->pluck('name')->sort()->values()->all())->toBe(['Supervisor', 'owner'])
        ->and($roles->firstWhere('name', 'owner')['is_system'])->toBeTrue()
        ->and($roles->firstWhere('name', 'Supervisor')['is_system'])->toBeFalse();
});

test('back office permissions never reach a workspace', function () {
    $a = roleScopeOwner('A');

    $platform = Permission::findOrCreate('bo.health.view', 'web');
    $platform->forceFill(['is_platform' => true])->save();
    // One created without the flag, as some migrations once did.
    Permission::create(['name' => 'bo.leaked.view', 'guard_name' => 'web']);
    Role::findByName('owner', 'web')->givePermissionTo($platform);

    $names = collect($this->actingAs($a, 'sanctum')->getJson('/api/permissions')->assertOk()->json('data'))->pluck('name');
    expect($names)->not->toContain('bo.health.view')
        ->and($names)->not->toContain('bo.leaked.view')
        ->and($names)->toContain('leads.view');

    $owner = collect($this->actingAs($a, 'sanctum')->getJson('/api/roles')->json('data'))->firstWhere('name', 'owner');
    expect(collect($owner['permissions'])->pluck('name'))->not->toContain('bo.health.view');

    $this->actingAs($a, 'sanctum')
        ->postJson('/api/roles', ['name' => 'Espiã', 'permissions' => ['bo.health.view']])
        ->assertUnprocessable();

    $agent = roleScopeAgent($a, 'agent@a.example');
    $this->actingAs($a, 'sanctum')
        ->postJson("/api/agents/{$agent->id}/assign-permissions", ['permissions' => ['bo.health.view']])
        ->assertUnprocessable();
});

test("another workspace's role can be neither edited nor deleted", function () {
    $a = roleScopeOwner('A');
    $b = roleScopeOwner('B');
    $roleB = roleScopeCreate($b, 'Vendas');

    $this->actingAs($a, 'sanctum')
        ->putJson("/api/roles/{$roleB->id}", ['name' => 'Roubada', 'permissions' => []])
        ->assertNotFound();
    $this->actingAs($a, 'sanctum')->deleteJson("/api/roles/{$roleB->id}")->assertNotFound();

    expect($roleB->fresh()->name)->toBe('Vendas');
});

test('the owner role can be neither edited nor deleted', function () {
    $a = roleScopeOwner('A');
    $ownerRole = Role::findByName('owner', 'web');

    $this->actingAs($a, 'sanctum')
        ->putJson("/api/roles/{$ownerRole->id}", ['name' => 'Chefe', 'permissions' => []])
        ->assertForbidden()
        ->assertJsonPath('code', 'role_protected');
    $this->actingAs($a, 'sanctum')->deleteJson("/api/roles/{$ownerRole->id}")->assertForbidden();

    expect($ownerRole->fresh()->name)->toBe('owner');
});

test('reserved names are refused', function () {
    $a = roleScopeOwner('A');
    Role::create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);

    foreach (['Owner', 'owner', 'SUPPORT'] as $name) {
        $this->actingAs($a, 'sanctum')
            ->postJson('/api/roles', ['name' => $name])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This name is reserved. Choose another one.');
    }
});

test("assigning a role by name picks the workspace's own role", function () {
    $a = roleScopeOwner('A');
    $b = roleScopeOwner('B');
    $roleA = roleScopeCreate($a, 'Supervisor');
    roleScopeCreate($b, 'Supervisor');
    $agent = roleScopeAgent($a, 'agent@a.example');

    $this->actingAs($a, 'sanctum')
        ->postJson("/api/agents/{$agent->id}/assign-roles", ['roles' => ['Supervisor']])
        ->assertOk();

    expect($agent->fresh()->roles->pluck('id')->all())->toBe([$roleA->id]);
});

test("another workspace's role cannot be assigned", function () {
    $a = roleScopeOwner('A');
    $b = roleScopeOwner('B');
    roleScopeCreate($b, 'Vendas');
    $agent = roleScopeAgent($a, 'agent@a.example');

    $this->actingAs($a, 'sanctum')
        ->postJson("/api/agents/{$agent->id}/assign-roles", ['roles' => ['Vendas']])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The selected role is invalid.');

    expect($agent->fresh()->roles)->toBeEmpty();
});

test("creating an attendant with another workspace's role is refused and creates nobody", function () {
    config(['services.billing.enforce' => false]);
    $a = roleScopeOwner('A');
    $b = roleScopeOwner('B');
    roleScopeCreate($b, 'Vendas');

    $this->actingAs($a, 'sanctum')
        ->postJson('/api/agents', [
            'name' => 'Nova',
            'email' => 'nova@a.example',
            'password' => 'secret123',
            'roles' => ['Vendas'],
        ])
        ->assertUnprocessable();

    expect(User::where('email', 'nova@a.example')->exists())->toBeFalse();
});

test('the last role and the last extra permission can be taken away', function () {
    $a = roleScopeOwner('A');
    $role = roleScopeCreate($a, 'Supervisor');
    $agent = roleScopeAgent($a, 'agent@a.example');
    $agent->assignRole($role);
    $agent->givePermissionTo('contacts.update');

    $this->actingAs($a, 'sanctum')
        ->postJson("/api/agents/{$agent->id}/assign-roles", ['roles' => []])
        ->assertOk();
    $this->actingAs($a, 'sanctum')
        ->postJson("/api/agents/{$agent->id}/assign-permissions", ['permissions' => []])
        ->assertOk();

    $agent = $agent->fresh();
    expect($agent->roles)->toBeEmpty()
        ->and($agent->permissions)->toBeEmpty();
});

test('the owner role cannot be handed out', function () {
    $a = roleScopeOwner('A');
    $agent = roleScopeAgent($a, 'agent@a.example');

    $this->actingAs($a, 'sanctum')
        ->postJson("/api/agents/{$agent->id}/assign-roles", ['roles' => ['owner']])
        ->assertForbidden();

    expect($agent->fresh()->hasRole('owner'))->toBeFalse();
});

test('the role list is readable by whoever may assign roles', function () {
    $a = roleScopeOwner('A');
    roleScopeCreate($a, 'Supervisor');

    $assigner = roleScopeAgent($a, 'rh@a.example');
    $assigner->givePermissionTo('agents.assign-roles');

    $this->actingAs($assigner, 'sanctum')->getJson('/api/roles')->assertOk();
});

test('a missing permission answers with a code the dashboard can translate', function () {
    $a = roleScopeOwner('A');
    $agent = roleScopeAgent($a, 'agent@a.example');
    $agent->givePermissionTo('agents.view');

    $this->actingAs($agent, 'sanctum')
        ->getJson('/api/roles')
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'You do not have permission to do this.',
            'code' => 'forbidden',
        ]);
});

test('the back office keeps its own 403', function () {
    $role = Role::create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);
    Permission::findOrCreate('bo.health.view', 'web')->forceFill(['is_platform' => true])->save();

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/health')->assertForbidden();

    expect($response->json('code'))->toBeNull();
});
