<?php

use App\Enums\Billing\Addon;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use App\Services\Billing\PlanChange;
use App\Services\Billing\SubscriptionAddons;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Extra agents and WhatsApp numbers bought on top of a plan, at the plan's own
 * price for them: chosen at checkout and charged with the plan, bought mid-
 * period at a prorated price and added once paid, and from then on part of
 * every renewal — on whichever gateway already renews the plan.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
    Carbon::setTestNow('2026-10-16 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function addonTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'addon-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

/**
 * A plan that includes `$agents` seats and `$whatsapp` numbers, and sells one
 * more of each at the given prices (null = not sold).
 */
function addonPlan(string $name, int $cents, int $agents, int $whatsapp, ?int $extraAgent, ?int $extraWhatsapp, BillingCycle $cycle = BillingCycle::Monthly): Plan
{
    $plan = Plan::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'price_cents' => $cents,
        'currency' => 'BRL',
        'billing_cycle' => $cycle,
        'trial_days' => 0,
        'is_active' => true,
        'is_public' => true,
        'quotas' => ['max_agents' => $agents, 'max_whatsapp_connections' => $whatsapp],
    ]);

    $plan->marketPrices()->first()->update(['addon_cents' => array_filter([
        'agents' => $extraAgent,
        'whatsapp_connections' => $extraWhatsapp,
    ])]);

    return $plan->fresh();
}

/** A paid, running plan: 2026-10-01 → 2026-10-31, and "now" is the 16th — half left. */
function addonActive(Tenant $tenant, Plan $plan, PaymentMethod $method = PaymentMethod::Pix, array $attributes = []): Subscription
{
    $subscription = Subscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => $method,
        'billing_cycle' => $plan->billing_cycle,
        'price_cents' => $plan->price_cents,
        'currency' => 'BRL',
        'quotas_snapshot' => $plan->quotas,
        'features_snapshot' => $plan->features,
        'current_period_start' => '2026-10-01 12:00:00',
        'current_period_end' => '2026-10-31 12:00:00',
    ], $attributes));

    Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'status' => InvoiceStatus::Paid,
        'payment_method' => $method,
        'amount_cents' => $subscription->cycleTotalCents(),
        'currency' => 'BRL',
        'period_start' => '2026-10-01 12:00:00',
        'period_end' => '2026-10-31 12:00:00',
        'paid_at' => '2026-10-01 12:00:00',
        'order_reference' => "pingly-sub-{$subscription->id}-2026-10-01",
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription->fresh();
}

function addonPix(string $id = '901'): array
{
    return ['data' => [
        'id' => $id,
        'order_reference' => 'ref',
        'status' => 'pending',
        'instructions' => ['type' => 'pix', 'qr_code' => 'QR', 'qr_code_image' => 'IMG'],
    ]];
}

// --- What a plan sells ----------------------------------------------------------

it('sells an extra only where the plan limits it and a price was typed for it', function () {
    $plan = addonPlan('One', 14990, 2, 1, 5990, null);
    $unlimited = Plan::create([
        'name' => 'Open', 'slug' => 'open-'.uniqid(), 'price_cents' => 9900, 'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly, 'is_active' => true, 'is_public' => true,
        'quotas' => ['max_whatsapp_connections' => 1],
    ]);
    $unlimited->marketPrices()->first()->update(['addon_cents' => ['agents' => 1000, 'whatsapp_connections' => 2000]]);

    expect($plan->addonUnitPrices('BR'))->toBe(['agents' => 5990])
        // No agent limit, so no agent to add; the WhatsApp extra is sold.
        ->and($unlimited->fresh()->addonUnitPrices('BR'))->toBe(['whatsapp_connections' => 2000])
        ->and($plan->addonUnitPrices('ID'))->toBe([]);
});

it('lists each plan cycle with the price of its extras', function () {
    $tenant = addonTenant();
    $plan = addonPlan('One', 14990, 2, 1, 5990, 16990);
    $user = User::find($tenant->user_id);
    $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('owner', 'web'));
    \Spatie\Permission\Models\Permission::findOrCreate('billing.view', 'web');
    $user->givePermissionTo('billing.view');

    $res = $this->actingAs($user)->getJson('/api/plans')->assertOk();

    expect($res->json('data.0.cycle_prices.0.addon_cents'))->toBe(['agents' => 5990, 'whatsapp_connections' => 16990]);
});

// --- Checkout -------------------------------------------------------------------

it('charges the extras chosen at checkout with the plan, and raises the limits once paid', function () {
    Http::fake(['*/payments' => Http::response(addonPix())]);
    $tenant = addonTenant();
    $plan = addonPlan('One', 14990, 2, 1, 5990, 16990);

    $subscription = app(BillingService::class)->subscribe($tenant, $plan, PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
        'addons' => ['agents' => 3, 'whatsapp_connections' => 1],
    ]);

    $invoice = $subscription->invoices()->first();

    // 149,90 + 3 × 59,90 + 1 × 169,90
    expect($invoice->amount_cents)->toBe(14990 + 3 * 5990 + 16990)
        ->and($subscription->addons)->toBe(['agents' => 3, 'whatsapp_connections' => 1])
        ->and($subscription->addon_unit_cents)->toBe(['agents' => 5990, 'whatsapp_connections' => 16990]);

    app(BillingService::class)->applyPaymentUpdate(['id' => '901', 'status' => 'paid']);
    $gate = app(SubscriptionGate::class);
    $gate->forget($tenant);

    expect($gate->quota($tenant->fresh(), 'max_agents'))->toBe(5)
        ->and($gate->quota($tenant->fresh(), 'max_whatsapp_connections'))->toBe(2);
});

it('drops units of an extra the plan does not sell instead of charging them', function () {
    $tenant = addonTenant();
    $plan = addonPlan('One', 14990, 2, 1, 5990, null);

    $quote = app(PlanChange::class)->quote($tenant, $plan, PaymentMethod::Pix, addons: ['agents' => 2, 'whatsapp_connections' => 4]);

    expect($quote['addons']['units'])->toBe(['agents' => 2])
        ->and($quote['charge_now_cents'])->toBe(14990 + 2 * 5990)
        ->and($quote['total_cents'])->toBe(14990 + 2 * 5990);
});

it('keeps the capacity, not the number of units, when the plan changes', function () {
    $tenant = addonTenant();
    $one = addonPlan('One', 14990, 2, 1, 5990, 16990);
    addonActive($tenant, $one, attributes: [
        'addons' => ['agents' => 3, 'whatsapp_connections' => 2],
        'addon_unit_cents' => ['agents' => 5990, 'whatsapp_connections' => 16990],
    ]);
    $prime = addonPlan('Prime', 24990, 4, 2, 5490, 14990);

    $quote = app(PlanChange::class)->quote($tenant->fresh(), $prime, PaymentMethod::Pix);

    // 5 seats and 3 numbers today: Prime includes 4 and 2, so one of each.
    expect($quote['addons']['units'])->toBe(['agents' => 1, 'whatsapp_connections' => 1])
        // At Prime's price for them, not One's.
        ->and($quote['total_cents'])->toBe(24990 + 5490 + 14990);
});

it('compares plans by their total with extras, so a bigger plan can be the cheaper cycle', function () {
    $tenant = addonTenant();
    $one = addonPlan('One', 14990, 2, 1, 5990, 16990);
    addonActive($tenant, $one, attributes: [
        'addons' => ['whatsapp_connections' => 4],
        'addon_unit_cents' => ['whatsapp_connections' => 16990],
    ]);
    // 149,90 + 4 × 169,90 = 829,50 a month today; Black is 699 with 5 numbers.
    $black = addonPlan('Black', 69900, 5, 5, 4990, 8990);

    $quote = app(PlanChange::class)->quote($tenant->fresh(), $black);

    expect($quote['type'])->toBe(PlanChange::DOWNGRADE)
        ->and($quote['addons']['units'])->toBe([])
        ->and($quote['next_renewal_cents'])->toBe(69900);
});

// --- Renewals -------------------------------------------------------------------

it('renews the plan and its extras in one charge', function () {
    $tenant = addonTenant();
    $subscription = addonActive($tenant, addonPlan('One', 14990, 2, 1, 5990, 16990), PaymentMethod::Card, [
        'payment_instrument_id' => 'inst_1',
        'addons' => ['agents' => 2],
        'addon_unit_cents' => ['agents' => 5990],
        'current_period_end' => '2026-10-17 12:00:00',
    ]);

    Http::fake(['*/payments' => Http::response(['data' => ['id' => 'pay_r', 'status' => 'paid']])]);

    $invoice = app(BillingService::class)->chargeRenewal($subscription);

    expect($invoice->amount_cents)->toBe(14990 + 2 * 5990);
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/payments')
        && $request['amount'] === 14990 + 2 * 5990
        && $request['instrument_id'] === 'inst_1');
});

it('issues a Pix renewal at the plan and its extras', function () {
    Http::fake(['*/payments' => Http::response(addonPix('902'))]);
    $tenant = addonTenant();
    $subscription = addonActive($tenant, addonPlan('One', 14990, 2, 1, 5990, 16990), attributes: [
        'addons' => ['whatsapp_connections' => 1],
        'addon_unit_cents' => ['whatsapp_connections' => 16990],
    ]);

    $invoice = app(BillingService::class)->createCycleInvoice($subscription, PaymentMethod::Pix);

    expect($invoice->amount_cents)->toBe(14990 + 16990);
});

// --- Buying mid-period ------------------------------------------------------------

it('prices extras bought mid-period for what is left of it, and adds them only once paid', function () {
    Http::fake(['*/payments' => Http::response(addonPix('903'))]);
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000));
    $addons = app(SubscriptionAddons::class);

    $quote = $addons->quote($tenant->fresh(), ['agents' => 2]);

    // Half of October left: 2 × 60,00 × ½.
    expect($quote['charge_now_cents'])->toBe(6000)
        ->and($quote['next_renewal_cents'])->toBe(14990 + 2 * 6000);

    $result = $addons->purchase($tenant->fresh(), ['agents' => 2], ['method' => 'pix']);

    expect($result['invoice']->purpose)->toBe(InvoicePurpose::SubscriptionAddon)
        ->and($result['invoice']->amount_cents)->toBe(6000)
        ->and($result['invoice']->pix_qr_code)->toBe('QR')
        ->and($tenant->fresh()->currentSubscription->addons)->toBeNull();

    app(BillingService::class)->applyPaymentUpdate(['id' => '903', 'status' => 'paid']);

    $subscription = $tenant->fresh()->currentSubscription;

    expect($subscription->addons)->toBe(['agents' => 2])
        ->and($subscription->addon_unit_cents)->toBe(['agents' => 6000])
        ->and($subscription->entitlements()['quotas']['max_agents'])->toBe(4)
        ->and(PlanChange::termsFor($subscription, $subscription->current_period_end)['price_cents'])->toBe(14990 + 2 * 6000);

    // A repeated notification adds nothing more.
    app(BillingService::class)->applyPaymentUpdate(['id' => '903', 'status' => 'paid']);
    expect($tenant->fresh()->currentSubscription->addons)->toBe(['agents' => 2]);
});

it('charges the card the plan renews on, with the customer at the screen', function () {
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000), PaymentMethod::Card, [
        'payment_instrument_id' => 'inst_1',
        'payment_card' => ['brand' => 'visa', 'last_four' => '4242'],
    ]);

    Http::fake(['*/payments' => Http::response(['data' => ['id' => 'pay_a', 'status' => 'paid']])]);

    $options = app(SubscriptionAddons::class)->state($tenant->fresh())['payment_options'];
    $result = app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['whatsapp_connections' => 1], ['method' => 'card_on_file']);

    expect($options[0])->toBe(['method' => 'card_on_file', 'card' => ['brand' => 'visa', 'last_four' => '4242']])
        ->and($result['payment']['status'])->toBe('paid')
        ->and($tenant->fresh()->currentSubscription->addons)->toBe(['whatsapp_connections' => 1]);

    Http::assertSent(fn (HttpRequest $request) => $request['instrument_id'] === 'inst_1'
        && $request['amount'] === 8500
        && $request['order_reference'] === "pingly-addon-{$result['invoice']->id}");
});

it('adds nothing when the card is refused', function () {
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000), PaymentMethod::Card, ['payment_instrument_id' => 'inst_1']);

    Http::fake(['*/payments' => Http::response(['data' => ['id' => 'pay_d', 'status' => 'declined', 'decline' => ['category' => 'insufficient_funds']]])]);

    expect(fn () => app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 1], ['method' => 'card_on_file']))
        ->toThrow(\App\Exceptions\Billing\CardDeclinedException::class);

    expect($tenant->fresh()->currentSubscription->addons)->toBeNull()
        ->and(Invoice::where('purpose', 'subscription_addon')->value('status'))->toBe(InvoiceStatus::Failed);
});

it('charges the kept Stripe card on-session so 3-D Secure can be finished in place', function () {
    config(['services.billing.payment_method' => 'direct']);
    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Setting::set(DirectBillingConfig::MP_PUBLIC_KEY, 'APP_USR-public');
    Setting::set(DirectBillingConfig::STRIPE_SECRET_KEY, 'sk_test_secret');
    Setting::set(DirectBillingConfig::STRIPE_PUBLISHABLE_KEY, 'pk_test_public');

    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000), PaymentMethod::Card, [
        'gateway' => 'stripe',
        'payment_instrument_id' => 'stripe_pm:cus_1:pm_1',
    ]);

    Http::fake([
        'api.mercadopago.com/*' => Http::response([]),
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_a', 'object' => 'payment_intent', 'status' => 'requires_action',
            'client_secret' => 'pi_a_secret', 'customer' => 'cus_1', 'payment_method' => 'pm_1', 'metadata' => [],
        ]),
    ]);

    $result = app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 1], ['method' => 'card_on_file']);

    expect($result['payment'])->toBe(['status' => 'requires_action', 'client_secret' => 'pi_a_secret'])
        ->and($result['invoice']->meta['authentication']['client_secret'])->toBe('pi_a_secret')
        ->and($tenant->fresh()->currentSubscription->addons)->toBeNull();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'payment_intents')
        && $request['payment_method'] === 'pm_1'
        && ! isset($request['off_session']));
});

it('closes an earlier unpaid purchase before starting another', function () {
    Http::fake(['*/payments' => Http::sequence()->push(addonPix('904'))->push(addonPix('905'))->push(['data' => ['id' => '904', 'status' => 'pending']])]);
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000));

    $first = app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 1], ['method' => 'pix'])['invoice'];
    Http::fake(['*/payments/904' => Http::response(['data' => ['id' => '904', 'status' => 'pending']]), '*/payments' => Http::response(addonPix('905'))]);
    app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 2], ['method' => 'pix']);

    expect($first->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Invoice::where('purpose', 'subscription_addon')->where('status', 'pending')->count())->toBe(1);
});

it('closes a Pix renewal already issued at the old total once extras are paid', function () {
    Http::fake(['*/payments' => Http::sequence()->push(addonPix('906'))->push(addonPix('907'))]);
    $tenant = addonTenant();
    $subscription = addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000));
    $renewal = app(BillingService::class)->createCycleInvoice($subscription, PaymentMethod::Pix);

    app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 1], ['method' => 'pix']);
    app(BillingService::class)->applyPaymentUpdate(['id' => '907', 'status' => 'paid']);

    expect($renewal->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

// --- Fewer extras ---------------------------------------------------------------

it('schedules fewer extras for the end of the paid period, with nothing to pay', function () {
    $tenant = addonTenant();
    $subscription = addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000), attributes: [
        'addons' => ['agents' => 3],
        'addon_unit_cents' => ['agents' => 6000],
    ]);

    $result = app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 1]);
    $subscription->refresh();

    expect($result['invoice'])->toBeNull()
        ->and($subscription->addons)->toBe(['agents' => 3])
        ->and($subscription->scheduled_addons)->toBe(['agents' => 1])
        ->and($subscription->scheduled_addons_at->toDateTimeString())->toBe('2026-10-31 12:00:00')
        ->and(PlanChange::termsFor($subscription, $subscription->current_period_end)['price_cents'])->toBe(14990 + 6000);

    Carbon::setTestNow('2026-10-31 13:00:00');
    app(BillingService::class)->applyDueScheduledChanges();

    expect($subscription->fresh()->addons)->toBe(['agents' => 1])
        ->and($subscription->fresh()->scheduled_addons_at)->toBeNull();
});

it('refuses to lower extras below what is in use', function () {
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 2, 1, 6000, 17000), attributes: [
        'addons' => ['whatsapp_connections' => 2],
        'addon_unit_cents' => ['whatsapp_connections' => 17000],
    ]);

    foreach (range(1, 3) as $i) {
        Connection::create(['tenant_id' => $tenant->id, 'channel' => 'whatsapp_official', 'name' => "WA {$i}", 'status' => 'active']);
    }

    expect(fn () => app(SubscriptionAddons::class)->quote($tenant->fresh(), ['whatsapp_connections' => 1]))
        ->toThrow(ValidationException::class);
});

// --- Plan changes -----------------------------------------------------------------

it('carries the extras into a scheduled downgrade at the cheaper plan\'s price', function () {
    $tenant = addonTenant();
    $prime = addonPlan('Prime', 24990, 4, 2, 5490, 14990);
    $subscription = addonActive($tenant, $prime, attributes: [
        'addons' => ['agents' => 2],
        'addon_unit_cents' => ['agents' => 5490],
    ]);
    $one = addonPlan('One', 14990, 2, 1, 5990, 16990);

    // Keeping all six seats on One would cost more than Prime (an upgrade,
    // paid now); keeping three is the cheaper cycle that waits.
    expect(app(PlanChange::class)->quote($tenant->fresh(), $one)['type'])->toBe(PlanChange::UPGRADE);

    app(BillingService::class)->schedulePlanChange($tenant->fresh(), $one, ['agents' => 1]);
    $subscription->refresh();

    // At One's 59,90, not Prime's 54,90.
    expect($subscription->scheduled_addons)->toBe(['agents' => 1])
        ->and(PlanChange::termsFor($subscription, $subscription->current_period_end)['price_cents'])->toBe(14990 + 5990);

    Carbon::setTestNow('2026-10-31 13:00:00');
    app(BillingService::class)->applyDueScheduledChanges();
    $subscription->refresh();

    expect($subscription->plan_id)->toBe($one->id)
        ->and($subscription->addons)->toBe(['agents' => 1])
        ->and($subscription->addon_unit_cents)->toBe(['agents' => 5990])
        ->and($subscription->entitlements()['quotas']['max_agents'])->toBe(3);
});

it('credits what is left of paid extras against an upgrade', function () {
    Http::fake(['*/payments' => Http::response(addonPix('908'))]);
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 10000, 2, 1, 6000, 17000));
    app(SubscriptionAddons::class)->purchase($tenant->fresh(), ['agents' => 2], ['method' => 'pix']);
    app(BillingService::class)->applyPaymentUpdate(['id' => '908', 'status' => 'paid']);

    $quote = app(PlanChange::class)->quote($tenant->fresh(), addonPlan('Max', 50000, 10, 10, 1000, 1000), PaymentMethod::Pix);

    // Half of 100,00 for the plan + all of the 60,00 paid for half the period.
    expect($quote['type'])->toBe(PlanChange::UPGRADE)
        ->and($quote['credit_cents'])->toBe(5000 + 6000);
});

it('is not offered on a plan granted by hand, or while a downgrade waits', function () {
    $tenant = addonTenant();
    $plan = addonPlan('One', 14990, 2, 1, 6000, 17000);
    addonActive($tenant, $plan, PaymentMethod::Manual, ['status' => SubscriptionStatus::Manual]);

    expect(app(SubscriptionAddons::class)->state($tenant->fresh())['reason'])->toBe('manual');

    $other = addonTenant();
    $subscription = addonActive($other, $plan);
    $subscription->update(['scheduled_plan_id' => $plan->id, 'scheduled_price_cents' => 100, 'scheduled_change_at' => '2026-10-31 12:00:00']);

    expect(app(SubscriptionAddons::class)->state($other->fresh())['reason'])->toBe('scheduled_change');
});

// --- Limits reached ---------------------------------------------------------------

it('tells a full workspace an extra seat can be bought', function () {
    $tenant = addonTenant();
    addonActive($tenant, addonPlan('One', 14990, 1, 1, 6000, 17000));
    $owner = User::find($tenant->user_id);
    \Spatie\Permission\Models\Permission::findOrCreate('agents.create', 'web');
    $owner->givePermissionTo('agents.create');
    config(['services.billing.enforce' => true]);

    $this->actingAs($owner)->postJson('/api/agents', [
        'name' => 'Bruno', 'email' => 'bruno-'.uniqid().'@example.test', 'password' => 'SenhaForte!2026',
    ])->assertStatus(422)->assertJson(['code' => 'quota_exceeded', 'quota' => 'max_agents', 'addon_available' => true]);
});

// --- Starting prices ----------------------------------------------------------------

it('prices the public plans\' extras from the landing page, by price rather than by name', function () {
    // Production names the R$ 699 plan ELITE; the landing page calls it BLACK.
    $elite = Plan::create([
        'name' => 'ELITE', 'slug' => 'elite-'.uniqid(), 'price_cents' => 69900, 'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly, 'is_active' => true, 'is_public' => true,
        'quotas' => ['max_agents' => 5, 'max_whatsapp_connections' => 5],
    ]);
    $elite->marketPrices()->create(['market_code' => 'BR', 'billing_cycle' => 'yearly', 'amount_cents' => 671040, 'currency' => 'BRL']);
    $other = addonPlan('Test', 100, 99, 99, null, null);
    $other->marketPrices()->update(['addon_cents' => null]);

    (require database_path('migrations/2026_11_02_000300_seed_landing_addon_prices.php'))->up();

    expect($elite->fresh()->addonUnitPrices('BR', 'monthly'))->toBe(['agents' => 4990, 'whatsapp_connections' => 8990])
        ->and($elite->fresh()->addonUnitPrices('BR', 'yearly'))->toBe(['agents' => 47904, 'whatsapp_connections' => 86304])
        ->and($other->fresh()->addonUnitPrices('BR'))->toBe([]);
});
