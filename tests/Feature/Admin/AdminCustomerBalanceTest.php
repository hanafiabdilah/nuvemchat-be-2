<?php

use App\Models\Admin;
use App\Models\CreditWallet;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The customer list shows each workspace's prepaid balance, and the dashboard
 * the total held — both only for roles that manage balances.
 */
function balanceAdmin(array $permissions): Admin
{
    $role = Role::findOrCreate('balance-role-'.uniqid(), 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function balanceTenant(?int $cents, string $currency = 'BRL'): Tenant
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    if ($cents !== null) {
        CreditWallet::query()->create(['tenant_id' => $tenant->id, 'balance_cents' => $cents, 'currency' => $currency]);
    }

    return $tenant->fresh();
}

test('the customer list carries the balance for roles that manage balances', function () {
    $tenant = balanceTenant(3800);
    balanceTenant(null);

    $res = $this->actingAs(balanceAdmin(['bo.customers.view', 'bo.credits.manage']), 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk();

    $rows = collect($res->json('data'))->keyBy('id');
    expect($rows[$tenant->id]['balance'])->toBe(['cents' => 3800, 'currency' => 'BRL'])
        ->and($rows->firstWhere('id', '!=', $tenant->id)['balance'])->toBeNull();

    $this->actingAs(balanceAdmin(['bo.customers.view', 'bo.credits.manage']), 'sanctum')
        ->getJson("/api/admin/customers/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('data.balance.cents', 3800);
});

test('the balance is left out for roles that do not manage balances', function () {
    balanceTenant(3800);

    $res = $this->actingAs(balanceAdmin(['bo.customers.view']), 'sanctum')
        ->getJson('/api/admin/customers')
        ->assertOk();

    expect($res->json('data.0'))->not->toHaveKey('balance');
});

test('the summary totals held balance per currency and ignores debts', function () {
    balanceTenant(5000);
    balanceTenant(1200);
    balanceTenant(-300);
    balanceTenant(150000, 'IDR');

    $this->actingAs(balanceAdmin(['bo.credits.manage']), 'sanctum')
        ->getJson('/api/admin/credits/summary')
        ->assertOk()
        ->assertJsonPath('data.held', [
            ['currency' => 'BRL', 'cents' => 6200, 'wallets' => 2],
            ['currency' => 'IDR', 'cents' => 150000, 'wallets' => 1],
        ]);

    $this->actingAs(balanceAdmin(['bo.customers.view']), 'sanctum')
        ->getJson('/api/admin/credits/summary')
        ->assertForbidden();
});
