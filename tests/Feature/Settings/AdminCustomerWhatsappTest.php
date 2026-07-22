<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** A Back Office admin: platform role, no tenant scope, may read customers. */
function customersAdmin(): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.customers.view', 'web'));

    $admin = User::factory()->create(['tenant_id' => null]);
    $admin->assignRole($role);

    return $admin;
}

/** A customer (tenant) owned by a user with the given attributes. */
function customerOwnedBy(array $attributes): Tenant
{
    $owner = User::factory()->create($attributes);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

test('the customer list exposes the owner whatsapp number and its verified state', function () {
    customerOwnedBy([
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'whatsapp_number' => '5511999998888',
        'whatsapp_verified_at' => now(),
    ]);

    $this->actingAs(customersAdmin(), 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk()
        ->assertJsonPath('data.0.owner.whatsapp_number', '5511999998888')
        ->assertJsonPath('data.0.owner.whatsapp_verified', true);
});

test('an unverified number is reported as unverified rather than hidden', function () {
    customerOwnedBy([
        'whatsapp_number' => '5511777776666',
        'whatsapp_verified_at' => null,
    ]);

    $this->actingAs(customersAdmin(), 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk()
        ->assertJsonPath('data.0.owner.whatsapp_number', '5511777776666')
        ->assertJsonPath('data.0.owner.whatsapp_verified', false);
});

test('a legacy owner with no number yields null, not an error', function () {
    customerOwnedBy(['whatsapp_number' => null]);

    $this->actingAs(customersAdmin(), 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk()
        ->assertJsonPath('data.0.owner.whatsapp_number', null)
        ->assertJsonPath('data.0.owner.whatsapp_verified', false);
});

test('the detail endpoint carries the same fields', function () {
    $tenant = customerOwnedBy(['whatsapp_number' => '5511999998888', 'whatsapp_verified_at' => now()]);

    $this->actingAs(customersAdmin(), 'sanctum')
        ->getJson("/api/admin/customers/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('data.owner.whatsapp_number', '5511999998888')
        ->assertJsonPath('data.owner.whatsapp_verified', true);
});

// --- search -------------------------------------------------------------

test('searching by whatsapp number finds the customer', function () {
    customerOwnedBy(['name' => 'Ana', 'whatsapp_number' => '5511999998888']);
    customerOwnedBy(['name' => 'Bruno', 'whatsapp_number' => '5521111112222']);

    $res = $this->actingAs(customersAdmin(), 'sanctum')
        ->getJson('/api/admin/customers?search=99999')
        ->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.owner.name'))->toBe('Ana');
});

test('a number typed with + spaces and dashes still matches the stored digits', function () {
    customerOwnedBy(['name' => 'Ana', 'whatsapp_number' => '5511999998888']);

    $res = $this->actingAs(customersAdmin(), 'sanctum')
        ->getJson('/api/admin/customers?search=' . urlencode('+55 11 99999-8888'))
        ->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.owner.name'))->toBe('Ana');
});

test('searching by name and email still works', function () {
    customerOwnedBy(['name' => 'Ana', 'email' => 'ana@example.com', 'whatsapp_number' => '5511999998888']);
    customerOwnedBy(['name' => 'Bruno', 'email' => 'bruno@example.com', 'whatsapp_number' => '5521111112222']);

    $admin = customersAdmin();

    expect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?search=Bruno')->json('data'))
        ->toHaveCount(1);
    expect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/customers?search=ana@example')->json('data'))
        ->toHaveCount(1);
});
