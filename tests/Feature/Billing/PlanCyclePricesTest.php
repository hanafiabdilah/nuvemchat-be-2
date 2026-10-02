<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use App\Services\Billing\PlanChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * One plan, several prices: "One" is sold monthly and yearly from the same row,
 * with one set of quotas and features, instead of a second plan per cycle.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
    Carbon::setTestNow('2026-10-16 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function cycAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.plans.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function cycTenant(): array
{
    $user = User::factory()->create(['email' => 'cyc-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    foreach (['billing.view', 'billing.manage'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return [$tenant->fresh(), $user->fresh()];
}

/** "One": R$ 100 a month, R$ 1.080 a year (10% off twelve months). */
function cycPlan(string $name = 'One', int $monthly = 10000, ?int $yearly = 108000): Plan
{
    $plan = Plan::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'price_cents' => $monthly,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'trial_days' => 0,
        'is_active' => true,
        'is_public' => true,
        'quotas' => ['max_connections' => 3],
    ]);

    $prices = [['market_code' => 'BR', 'billing_cycle' => 'monthly', 'amount_cents' => $monthly]];

    if ($yearly !== null) {
        $prices[] = ['market_code' => 'BR', 'billing_cycle' => 'yearly', 'amount_cents' => $yearly];
    }

    $plan->syncMarketPrices($prices);

    return $plan->fresh();
}

test('the editor saves a monthly and a yearly price on the same plan', function () {
    $res = $this->actingAs(cycAdmin(), 'sanctum')->postJson('/api/admin/plans', [
        'name' => 'One',
        'features' => ['chat' => true],
        'quotas' => ['max_connections' => 3],
        'prices' => [
            ['market_code' => 'BR', 'billing_cycle' => 'yearly', 'amount_cents' => 108000, 'card_enabled' => true, 'pix_enabled' => true],
            ['market_code' => 'BR', 'billing_cycle' => 'monthly', 'amount_cents' => 10000, 'card_enabled' => true, 'pix_enabled' => true],
        ],
    ])->assertCreated();

    $plan = Plan::findOrFail($res->json('data.id'));

    expect(Plan::count())->toBe(1)
        // The row's own columns follow the shortest cycle sold at home.
        ->and($plan->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($plan->price_cents)->toBe(10000)
        ->and($plan->marketPrices()->count())->toBe(2)
        ->and($plan->priceForMarket('BR', BillingCycle::Yearly)->amount_cents)->toBe(108000)
        ->and(collect($res->json('data.prices'))->pluck('billing_cycle')->all())->toBe(['monthly', 'yearly']);
});

test('an editor that predates cycles cannot take the yearly price off sale', function () {
    $plan = cycPlan();

    $this->actingAs(cycAdmin(), 'sanctum')->putJson("/api/admin/plans/{$plan->id}", [
        'name' => 'One',
        'price_cents' => 12000,
        'billing_cycle' => 'monthly',
        'prices' => ['BR' => 12000],
    ])->assertOk();

    expect($plan->priceForMarket('BR', BillingCycle::Monthly)->amount_cents)->toBe(12000)
        ->and($plan->priceForMarket('BR', BillingCycle::Yearly)->amount_cents)->toBe(108000);
});

test('removing a cycle from the list stops selling it', function () {
    $plan = cycPlan();

    $this->actingAs(cycAdmin(), 'sanctum')->putJson("/api/admin/plans/{$plan->id}", [
        'name' => 'One',
        'prices' => [['market_code' => 'BR', 'billing_cycle' => 'yearly', 'amount_cents' => 108000]],
    ])->assertOk();

    $plan->refresh();

    expect($plan->isSoldIn('BR', BillingCycle::Monthly))->toBeFalse()
        ->and($plan->billing_cycle)->toBe(BillingCycle::Yearly)
        ->and($plan->price_cents)->toBe(108000);
});

test('the catalogue lists the plan once, with every cycle it is sold at', function () {
    cycPlan();
    [, $user] = cycTenant();

    $res = $this->actingAs($user, 'sanctum')->getJson('/api/plans')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.billing_cycle'))->toBe('monthly')
        ->and($res->json('data.0.price_cents'))->toBe(10000)
        ->and($res->json('data.0.cycle_prices'))->toBe([
            ['billing_cycle' => 'monthly', 'price_cents' => 10000, 'currency' => 'BRL'],
            ['billing_cycle' => 'yearly', 'price_cents' => 108000, 'currency' => 'BRL'],
        ]);
});

test('the quote prices the cycle that was picked', function () {
    $plan = cycPlan();
    [, $user] = cycTenant();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/billing/plan-change?plan_id={$plan->id}&billing_cycle=yearly")
        ->assertOk()
        ->assertJsonPath('data.plan.price_cents', 108000)
        ->assertJsonPath('data.plan.billing_cycle', 'yearly')
        ->assertJsonPath('data.charge_now_cents', 108000);

    // No cycle named: the plan's own, as before.
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/billing/plan-change?plan_id={$plan->id}")
        ->assertOk()
        ->assertJsonPath('data.plan.billing_cycle', 'monthly')
        ->assertJsonPath('data.plan.price_cents', 10000);
});

test('a cycle the plan is not sold at here is refused, not swapped', function () {
    $plan = cycPlan(yearly: null);
    [, $user] = cycTenant();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/billing/plan-change?plan_id={$plan->id}&billing_cycle=yearly")
        ->assertStatus(422)
        ->assertJsonValidationErrors('billing_cycle');
});

test('subscribing yearly snapshots the yearly price and a year-long period', function () {
    Http::fake(['*/payments' => Http::response(['data' => [
        'id' => '777',
        'order_reference' => 'ref',
        'status' => 'pending',
        'instructions' => ['type' => 'pix', 'qr_code' => 'QR', 'qr_code_image' => 'IMG'],
    ]])]);

    $plan = cycPlan();
    [$tenant] = cycTenant();

    $subscription = app(BillingService::class)->subscribe(
        $tenant,
        $plan->applyMarketPrice('BR', BillingCycle::Yearly),
        PaymentMethod::Pix,
        ['payer_email' => 'ana@example.test'],
    );

    $invoice = Invoice::where('subscription_id', $subscription->id)->firstOrFail();

    expect($subscription->billing_cycle)->toBe(BillingCycle::Yearly)
        ->and($subscription->price_cents)->toBe(108000)
        ->and($invoice->amount_cents)->toBe(108000)
        ->and($invoice->period_end->toDateString())->toBe('2027-10-16');
});

test('moving the same plan from monthly to yearly is an upgrade, not "already yours"', function () {
    $plan = cycPlan();
    [$tenant] = cycTenant();

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 10000,
        'currency' => 'BRL',
        'current_period_start' => '2026-10-01 12:00:00',
        'current_period_end' => '2026-10-31 12:00:00',
    ]);
    Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'status' => InvoiceStatus::Paid,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 10000,
        'currency' => 'BRL',
        'period_start' => '2026-10-01 12:00:00',
        'period_end' => '2026-10-31 12:00:00',
        'paid_at' => '2026-10-01 12:00:00',
        'order_reference' => "pingly-sub-{$subscription->id}-2026-10-01",
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    $change = app(PlanChange::class);

    expect($change->quote($tenant->fresh(), Plan::find($plan->id)->applyMarketPrice('BR', BillingCycle::Monthly))['type'])
        ->toBe(PlanChange::CURRENT)
        ->and($change->quote($tenant->fresh(), Plan::find($plan->id)->applyMarketPrice('BR', BillingCycle::Yearly))['type'])
        ->toBe(PlanChange::UPGRADE);
});

test('a trained agent price still has no cycle', function () {
    $plan = cycPlan();

    expect($plan->pricedPerCycle())->toBeTrue()
        ->and((new \App\Models\TrainedAgentBlueprint)->pricedPerCycle())->toBeFalse();
});
