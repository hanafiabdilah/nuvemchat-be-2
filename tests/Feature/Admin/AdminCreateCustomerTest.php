<?php

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function customerCreator(array $permissions = ['bo.customers.create']): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

function createCustomerIndonesia(): Market
{
    return Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'draft',
    ]);
}

beforeEach(function () {
    Role::findOrCreate('owner', 'web');
});

it('requires the create permission', function () {
    $this->actingAs(customerCreator(['bo.customers.view']), 'sanctum')
        ->postJson('/api/admin/customers', [])
        ->assertForbidden();
});

it('opens a workspace in the chosen market with an owner account', function () {
    createCustomerIndonesia();

    $response = $this->actingAs(customerCreator(), 'sanctum')
        ->postJson('/api/admin/customers', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'a-long-password',
            'whatsapp_number' => '+62 812-3456-7890',
            'whatsapp_verified' => true,
            'market_code' => 'ID',
        ])
        ->assertCreated()
        ->assertJsonPath('data.market_code', 'ID')
        ->assertJsonPath('data.owner.email', 'budi@example.com')
        ->assertJsonPath('data.owner.whatsapp_number', '6281234567890')
        ->assertJsonPath('data.owner.whatsapp_verified', true);

    $tenant = Tenant::findOrFail($response->json('data.id'));
    $owner = User::where('email', 'budi@example.com')->firstOrFail();

    expect($tenant->user_id)->toBe($owner->id)
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->hasRole('owner'))->toBeTrue()
        ->and($tenant->timezone)->toBe('Asia/Jakarta')
        ->and(AuditLog::where('action', 'customers.create')->exists())->toBeTrue();
});

it('leaves the number for the owner to confirm unless told otherwise', function () {
    $this->actingAs(customerCreator(), 'sanctum')
        ->postJson('/api/admin/customers', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'a-long-password',
            'whatsapp_number' => '5511999998888',
            'market_code' => 'BR',
        ])
        ->assertCreated()
        ->assertJsonPath('data.owner.whatsapp_verified', false);
});

it('refuses a taken email, an unknown market and a short number without writing anything', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $before = Tenant::count();

    $this->actingAs(customerCreator(), 'sanctum')
        ->postJson('/api/admin/customers', [
            'name' => 'X',
            'email' => 'taken@example.com',
            'password' => 'a-long-password',
            'market_code' => 'ZZ',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'market_code']);

    $this->actingAs(customerCreator(), 'sanctum')
        ->postJson('/api/admin/customers', [
            'name' => 'X',
            'email' => 'new@example.com',
            'password' => 'a-long-password',
            'whatsapp_number' => '123',
            'market_code' => 'BR',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_number']);

    expect(Tenant::count())->toBe($before);
});

it('serves the markets the form picks from', function () {
    createCustomerIndonesia();

    $this->actingAs(customerCreator(), 'sanctum')
        ->getJson('/api/admin/customers/meta')
        ->assertOk()
        ->assertJsonPath('default_market', 'BR')
        ->assertJsonFragment(['code' => 'ID', 'calling_code' => '62']);
});
