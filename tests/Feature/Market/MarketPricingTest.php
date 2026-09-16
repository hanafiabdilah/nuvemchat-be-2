<?php

use App\Enums\Billing\PaymentMethod;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TrainedAgentBlueprint;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\TrainedAgent\TrainedAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function pricingMarket(string $code = 'ID', string $currency = 'IDR'): Market
{
    return Market::create([
        'code' => $code,
        'name' => 'Indonesia',
        'currency' => $currency,
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

function pricingWorkspace(string $marketCode): User
{
    $owner = User::factory()->create();

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $owner->fresh();
}

function pricingPlan(array $prices, array $attributes = []): Plan
{
    $plan = Plan::create(array_merge([
        'name' => 'Starter',
        'slug' => 'starter-'.uniqid(),
        'price_cents' => 4990,
        'currency' => 'BRL',
        'billing_cycle' => 'monthly',
        'is_active' => true,
        'is_public' => true,
    ], $attributes));

    $plan->syncMarketPrices($prices);

    return $plan->fresh();
}

it('sells a plan only where it has a price', function () {
    pricingMarket();
    $plan = pricingPlan(['BR' => 4990]);

    expect($plan->isSoldIn('BR'))->toBeTrue()
        ->and($plan->isSoldIn('ID'))->toBeFalse()
        ->and($plan->priceForMarket('BR')->amount_cents)->toBe(4990)
        // The currency comes from the market, never from the form.
        ->and($plan->priceForMarket('BR')->currency)->toBe('BRL');
});

it('lists a country only the plans priced for it, at its own price', function () {
    pricingMarket();
    pricingPlan(['BR' => 4990], ['name' => 'Brasil só']);
    pricingPlan(['BR' => 9990, 'ID' => 14900000], ['name' => 'Global']);

    $user = pricingWorkspace('ID');
    $user->givePermissionTo(Permission::findOrCreate('billing.view', 'web'));
    Sanctum::actingAs($user);

    $plans = $this->getJson('/api/plans')->assertOk()->json('data');

    expect($plans)->toHaveCount(1)
        ->and($plans[0]['name'])->toBe('Global')
        // Rp 149.000, not the R$ 99,90 sitting on the plan row.
        ->and($plans[0]['price_cents'])->toBe(14900000)
        ->and($plans[0]['currency'])->toBe('IDR');
});

it('leaves the plan row itself in the platform currency', function () {
    pricingMarket();
    $plan = pricingPlan(['BR' => 9990, 'ID' => 14900000]);

    $plan->applyMarketPrice('ID');

    expect($plan->price_cents)->toBe(14900000)
        // In memory only: nothing that has not been taught about markets should
        // start reading a rupiah amount out of the plans table as reais. The
        // column keeps what the Back Office typed on the plan itself.
        ->and(Plan::findOrFail($plan->id)->price_cents)->toBe(4990);
});

it('refuses to subscribe to a plan that is not sold in the workspace country', function () {
    pricingMarket();
    $plan = pricingPlan(['BR' => 4990]);
    $tenant = pricingWorkspace('ID')->tenant;

    expect(fn () => app(BillingService::class)->subscribe($tenant, $plan, PaymentMethod::Pix, ['payer_email' => 'a@b.com']))
        ->toThrow(ValidationException::class);
});

it('replaces the whole price list, so removing a country stops the sale there', function () {
    pricingMarket();
    $plan = pricingPlan(['BR' => 4990, 'ID' => 14900000]);

    $plan->syncMarketPrices(['BR' => 5990]);

    expect($plan->fresh()->isSoldIn('ID'))->toBeFalse()
        ->and($plan->fresh()->priceForMarket('BR')->amount_cents)->toBe(5990);
});

it('ignores a price for a market that does not exist', function () {
    $plan = pricingPlan(['BR' => 4990, 'ZZ' => 100]);

    expect($plan->marketPrices()->count())->toBe(1);
});

it('shows a country only the trained agents priced for it', function () {
    pricingMarket();

    $brazilOnly = TrainedAgentBlueprint::create([
        'name' => 'Consultório', 'slug' => 'consultorio', 'model' => 'gpt-4o-mini',
        'system_prompt' => 'x', 'price_cents' => 9900, 'currency' => 'BRL',
        'is_active' => true, 'is_public' => true,
    ]);
    $brazilOnly->syncMarketPrices(['BR' => 9900]);

    $both = TrainedAgentBlueprint::create([
        'name' => 'Toko', 'slug' => 'toko', 'model' => 'gpt-4o-mini',
        'system_prompt' => 'x', 'price_cents' => 9900, 'currency' => 'BRL',
        'is_active' => true, 'is_public' => true,
    ]);
    $both->syncMarketPrices(['BR' => 9900, 'ID' => 29900000]);

    // Through the service rather than the endpoint: the catalog route sits
    // behind the plan's AI feature gate, and what is under test here is the
    // market filter, not the gate.
    $catalog = app(TrainedAgentService::class)->catalog(pricingWorkspace('ID')->tenant);

    expect($catalog)->toHaveCount(1)
        ->and($catalog->first()->name)->toBe('Toko')
        ->and($catalog->first()->price_cents)->toBe(29900000)
        ->and($catalog->first()->currency)->toBe('IDR');
});
