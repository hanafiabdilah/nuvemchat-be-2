<?php

use App\Models\Admin;
use App\Models\CreditWallet;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money\ExchangeRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function creditsMarketAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.credits.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

function creditsMarketWallet(string $code, string $currency, int $balance): Tenant
{
    $owner = User::factory()->create();

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $code;
    $tenant->save();
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $balance, 'currency' => $currency]);

    return $tenant;
}

beforeEach(function () {
    Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'draft',
    ]);
});

it('lists only the home market by default', function () {
    $br = creditsMarketWallet('BR', 'BRL', 5000);
    creditsMarketWallet('ID', 'IDR', 10000000);

    $response = $this->actingAs(creditsMarketAdmin(), 'sanctum')->getJson('/api/admin/credits')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.tenant_id'))->toBe($br->id)
        ->and($response->json('market.code'))->toBe('BR')
        ->and($response->json('market.currency'))->toBe('BRL')
        ->and($response->json('to_base_rate'))->toEqual(1)
        ->and(collect($response->json('markets'))->pluck('code')->all())->toContain('BR', 'ID');
});

it('lists one other market with a rate back to reais', function () {
    creditsMarketWallet('BR', 'BRL', 5000);
    $id = creditsMarketWallet('ID', 'IDR', 10000000);

    ExchangeRates::store(['BRL' => 5, 'IDR' => 16000]);

    $response = $this->actingAs(creditsMarketAdmin(), 'sanctum')
        ->getJson('/api/admin/credits?market=id')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.tenant_id'))->toBe($id->id)
        ->and($response->json('market.currency'))->toBe('IDR')
        ->and($response->json('base_currency'))->toBe('BRL')
        ->and((float) $response->json('to_base_rate'))->toBe(5 / 16000);
});

it('falls back to the home market for an unknown code', function () {
    creditsMarketWallet('BR', 'BRL', 5000);

    $this->actingAs(creditsMarketAdmin(), 'sanctum')
        ->getJson('/api/admin/credits?market=ZZ')
        ->assertOk()
        ->assertJsonPath('market.code', 'BR');
});
