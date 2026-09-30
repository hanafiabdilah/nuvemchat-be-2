<?php

use App\Enums\Market\MarketStatus;
use App\Models\Admin;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Impersonation opens the workspace on its own market's domain.
 *
 * The Back Office used to open every workspace on one configured address, so
 * an Indonesian workspace was inspected on the Brazilian domain — working, but
 * not what its owner sees.
 */
uses(RefreshDatabase::class);

function impersonationDomainAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.impersonate', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function impersonationDomainTarget(?string $marketCode = null): User
{
    $user = User::factory()->create();
    $tenant = (new Tenant)->forceFill(array_filter([
        'user_id' => $user->id,
        'market_code' => $marketCode,
    ]));
    $tenant->save();
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function impersonationDomainMarket(): Market
{
    return Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => MarketStatus::Draft,
    ]);
}

test('a workspace in a market with its own domain opens on that domain', function () {
    $market = impersonationDomainMarket();
    $market->domains()->create(['domain' => 'old.pingly.id', 'is_primary' => false]);
    $market->domains()->create(['domain' => 'chat.pingly.id', 'is_primary' => true]);

    $target = impersonationDomainTarget('ID');

    $this->actingAs(impersonationDomainAdmin(), 'sanctum')
        ->postJson('/api/admin/impersonate', ['user_id' => $target->id])
        ->assertOk()
        ->assertJsonPath('app_url', 'https://chat.pingly.id');
});

test('without a primary flag the oldest domain is used', function () {
    $market = impersonationDomainMarket();
    $market->domains()->create(['domain' => 'first.pingly.id', 'is_primary' => false]);
    $market->domains()->create(['domain' => 'second.pingly.id', 'is_primary' => false]);

    $target = impersonationDomainTarget('ID');

    $this->actingAs(impersonationDomainAdmin(), 'sanctum')
        ->postJson('/api/admin/impersonate', ['user_id' => $target->id])
        ->assertJsonPath('app_url', 'https://first.pingly.id');
});

test('a market without a domain leaves the choice to the Back Office', function () {
    $target = impersonationDomainTarget();

    expect($target->tenant->market_code)->toBe('BR');

    $this->actingAs(impersonationDomainAdmin(), 'sanctum')
        ->postJson('/api/admin/impersonate', ['user_id' => $target->id])
        ->assertOk()
        ->assertJsonPath('app_url', null);
});
