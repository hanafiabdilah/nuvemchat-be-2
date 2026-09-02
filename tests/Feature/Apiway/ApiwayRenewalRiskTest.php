<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Models\ApiwaySubscription;
use App\Models\CreditWallet;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Which renewals will fail for want of money.
 *
 * The only deadline on this platform with no undo: ProxyBR revokes on the
 * expiry date, permanently. Since the balance became the payment method there
 * is no invoice sitting in the billing page to pay late, so knowing this in
 * advance — on the customer's dashboard and on the operator's health page — is
 * the whole of the safety net.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

function riskTenant(int $balanceCents): Tenant
{
    $user = User::factory()->create(['email' => 'risk-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $balanceCents, 'currency' => 'BRL']);

    return $tenant->fresh();
}

function riskSubscription(Tenant $tenant, array $attributes = []): ApiwaySubscription
{
    return ApiwaySubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-'.uniqid(),
        'provider_subscription_id' => random_int(1000, 999999),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'unit_price_cents' => 4990,
        'total_price_cents' => 4990,
        'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Active,
        'expires_at' => now()->addDays(4),
    ], $attributes));
}

it('leaves a renewal alone when the balance covers it', function () {
    $tenant = riskTenant(10_000);
    riskSubscription($tenant);

    expect(app(ApiwayService::class)->renewalsAtRisk($tenant))->toBeEmpty();
});

it('flags a renewal the balance cannot cover', function () {
    $tenant = riskTenant(1_000);
    $row = riskSubscription($tenant);

    $atRisk = app(ApiwayService::class)->renewalsAtRisk($tenant);

    expect($atRisk)->toHaveCount(1)
        ->and($atRisk->first()->id)->toBe($row->id);
});

it('counts several subscriptions against one balance, in expiry order', function () {
    // The balance is shared. Checking each against the full balance would call
    // all three safe when the money only stretches to one.
    $tenant = riskTenant(6_000);
    $first = riskSubscription($tenant, ['expires_at' => now()->addDays(2)]);
    $second = riskSubscription($tenant, ['expires_at' => now()->addDays(4)]);
    $third = riskSubscription($tenant, ['expires_at' => now()->addDays(6)]);

    $atRisk = app(ApiwayService::class)->renewalsAtRisk($tenant);

    // The earliest is charged first and goes through; the later two are what
    // actually falls over, which is the order this has to report.
    expect($atRisk->pluck('id')->all())->toBe([$second->id, $third->id])
        ->and($atRisk->contains('id', $first->id))->toBeFalse();
});

it('ignores a renewal still on legacy card auto-debit', function () {
    // MercadoPago charges that one on its own schedule, so an empty balance
    // says nothing about whether it will renew.
    $tenant = riskTenant(0);
    riskSubscription($tenant, ['mp_preapproval_id' => 'PA-1']);

    expect(app(ApiwayService::class)->renewalsAtRisk($tenant))->toBeEmpty();
});

it('ignores renewals outside the window', function () {
    $tenant = riskTenant(0);
    riskSubscription($tenant, ['expires_at' => now()->addDays(30)]);

    expect(app(ApiwayService::class)->renewalsAtRisk($tenant))->toBeEmpty();
});

it('flags an included instance whose plan has lapsed, whatever the balance', function () {
    // Money is not the risk there — the free renewal rides on a plan that is no
    // longer usable, and no amount of balance changes that.
    $tenant = riskTenant(100_000);
    riskSubscription($tenant, ['source' => ApiwaySubscriptionSource::PlanIncluded]);

    expect(app(ApiwayService::class)->renewalsAtRisk($tenant))->toHaveCount(1);
});

it('serves the dashboard banner the deadline and the shortfall', function () {
    config()->set('ai.credits.low_balance_cents', 2_000);
    $tenant = riskTenant(1_000);
    riskSubscription($tenant, ['expires_at' => now()->addDays(3)]);

    $user = $tenant->user()->first();
    $role = Role::create(['name' => 'owner-'.uniqid(), 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'billing.view', 'guard_name' => 'web']));
    $user->assignRole($role);

    Sanctum::actingAs($user->fresh());

    $this->getJson('/api/credits/alerts')
        ->assertOk()
        ->assertJsonPath('data.renewals_at_risk.count', 1)
        ->assertJsonPath('data.renewals_at_risk.instances', 1)
        // What to add, not just that something is missing.
        ->assertJsonPath('data.renewals_at_risk.shortfall_cents', 3990)
        ->assertJsonPath('data.low_balance', true);
});
