<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Credit\CreditTransactionType;
use App\Exceptions\Billing\CouponException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Coupons;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\PlanChange;
use App\Services\Credits\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Coupons: a percentage off a plan's charges (once, N cycles or forever, with
 * or without the extras), or a bonus on top of a balance top-up. Only at
 * checkout; usage is read off the invoices that carry them.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['services.billing.payment_method' => 'direct']);

    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Setting::set(DirectBillingConfig::MP_PUBLIC_KEY, 'APP_USR-public');

    // Every Mercado Pago Pix gets an id of its own.
    Http::fake(['api.mercadopago.com/v1/payments' => function () {
        static $n = 0;
        $n++;

        return Http::response([
            'id' => 8000 + $n,
            'status' => 'pending',
            'status_detail' => 'pending_waiting_transfer',
            'external_reference' => 'ref',
            'date_of_expiration' => now()->addDay()->toIso8601String(),
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126PIX', 'qr_code_base64' => 'B64']],
        ]);
    }]);
});

function couponWorkspace(): Tenant
{
    $user = User::factory()->create(['email' => 'coupon-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = 'BR';
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    Permission::findOrCreate('billing.manage', 'web');
    Permission::findOrCreate('billing.view', 'web');
    $user->givePermissionTo(['billing.manage', 'billing.view']);

    $tenant->forceFill([
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ])->save();

    return $tenant->fresh();
}

/** A plan that sells extra agents at `$extraAgent` per cycle when given. */
function couponPlan(int $cents = 10000, string $name = 'Pro', ?int $extraAgent = null): Plan
{
    $plan = Plan::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'price_cents' => $cents,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'trial_days' => 0,
        'is_active' => true,
        'is_public' => true,
        'quotas' => ['max_agents' => 2],
    ]);

    if ($extraAgent !== null) {
        $plan->marketPrices()->first()->update(['addon_cents' => ['agents' => $extraAgent]]);
    }

    return $plan->fresh();
}

function makeCoupon(array $attributes = []): Coupon
{
    return Coupon::create(array_merge([
        'code' => 'PROMO'.strtoupper(Str::random(5)),
        'name' => 'Promoção',
        'scope' => 'plan',
        'percent_off' => 10,
        'duration' => 'once',
        'is_active' => true,
    ], $attributes));
}

function couponSubscribe(Tenant $tenant, Plan $plan, ?string $code = null, ?array $addons = null): Subscription
{
    return app(BillingService::class)->subscribe($tenant, $plan, PaymentMethod::Pix, array_filter([
        'payer_email' => 'ana@example.test',
        'coupon_code' => $code,
        'addons' => $addons,
    ], fn ($value) => $value !== null));
}

function couponPay(Invoice $invoice): void
{
    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->payment_id,
        'order_reference' => $invoice->order_reference,
        'status' => 'paid',
    ], 'mercadopago');
}

/** Pay the subscription's open cycle, then issue the next one. */
function couponNextCycle(Subscription $subscription): Invoice
{
    couponPay($subscription->invoices()->latest('id')->first());

    return app(BillingService::class)->createCycleInvoice($subscription->fresh(), PaymentMethod::Pix);
}

function couponAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.coupons.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

// --- Plan coupons: duration ----------------------------------------------------

it('takes a once coupon off the checkout charge only', function () {
    $coupon = makeCoupon(['percent_off' => 10]);
    $subscription = couponSubscribe(couponWorkspace(), couponPlan(10000), strtolower($coupon->code));

    $first = $subscription->invoices()->first();

    expect($first->amount_cents)->toBe(9000)
        ->and($first->coupon_id)->toBe($coupon->id)
        ->and($first->coupon_discount_cents)->toBe(1000)
        ->and($subscription->fresh()->coupon_terms['cycles'])->toBe(1);

    $renewal = couponNextCycle($subscription);

    expect($renewal->amount_cents)->toBe(10000)
        ->and($renewal->coupon_id)->toBeNull()
        ->and($renewal->coupon_discount_cents)->toBeNull();
});

it('discounts the first N cycles of a repeating coupon, then charges full price', function () {
    $coupon = makeCoupon(['percent_off' => 20, 'duration' => 'repeating', 'duration_cycles' => 2]);
    $subscription = couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);

    expect($subscription->invoices()->first()->amount_cents)->toBe(8000);

    $second = couponNextCycle($subscription);
    expect($second->amount_cents)->toBe(8000)->and($second->coupon_id)->toBe($coupon->id);

    $third = couponNextCycle($subscription);
    expect($third->amount_cents)->toBe(10000)->and($third->coupon_id)->toBeNull();
});

it('keeps discounting every cycle with a forever coupon', function () {
    $coupon = makeCoupon(['percent_off' => 15, 'duration' => 'forever']);
    $subscription = couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);

    couponNextCycle($subscription);
    couponNextCycle($subscription);
    $fourth = couponNextCycle($subscription);

    expect($fourth->amount_cents)->toBe(8500);
});

it('does not count an unpaid discounted cycle against the coupon', function () {
    $coupon = makeCoupon(['percent_off' => 20, 'duration' => 'repeating', 'duration_cycles' => 2]);
    $subscription = couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);

    couponPay($subscription->invoices()->first());
    // The second cycle's Pix expires unpaid: that cycle never used the coupon.
    $second = app(BillingService::class)->createCycleInvoice($subscription->fresh(), PaymentMethod::Pix);
    $second->update(['status' => InvoiceStatus::Expired]);

    $terms = PlanChange::termsFor($subscription->fresh(), $second->period_end);

    expect($terms['price_cents'])->toBe(8000)
        ->and($terms['coupon_id'])->toBe($coupon->id);
});

it('shows the running coupon and what the next renewal will charge', function () {
    $coupon = makeCoupon(['percent_off' => 20, 'duration' => 'repeating', 'duration_cycles' => 3]);
    $tenant = couponWorkspace();
    $subscription = couponSubscribe($tenant, couponPlan(10000), $coupon->code);
    couponPay($subscription->invoices()->first());

    $res = $this->actingAs($tenant->user, 'sanctum')->getJson('/api/billing/subscription');

    expect($res->json('data.coupon'))->toMatchArray([
        'code' => $coupon->code,
        'percent_off' => 20,
        'cycles_left' => 2,
    ])->and($res->json('data.next_renewal_cents'))->toBe(8000);
});

// --- Plan coupons: extras ------------------------------------------------------

it('discounts the extras too only when the coupon says so', function () {
    $plan = couponPlan(10000, 'Pro', extraAgent: 5000);
    $with = makeCoupon(['percent_off' => 10, 'applies_to_addons' => true]);
    $without = makeCoupon(['percent_off' => 10, 'applies_to_addons' => false]);

    $a = couponSubscribe(couponWorkspace(), $plan, $with->code, ['agents' => 2]);
    $b = couponSubscribe(couponWorkspace(), $plan, $without->code, ['agents' => 2]);

    // 10000 plan + 2 × 5000 extras = 20000.
    expect($a->invoices()->first()->amount_cents)->toBe(18000)
        ->and($b->invoices()->first()->amount_cents)->toBe(19000);
});

// --- Plan coupons: who may use it ----------------------------------------------

it('lets each workspace use a plan coupon once', function () {
    $coupon = makeCoupon(['duration' => 'once']);
    $tenant = couponWorkspace();
    $subscription = couponSubscribe($tenant, couponPlan(10000), $coupon->code);
    couponPay($subscription->invoices()->first());

    $subscription->fresh()->update(['status' => SubscriptionStatus::Cancelled]);

    expect(fn () => couponSubscribe($tenant->fresh(), couponPlan(20000, 'Max'), $coupon->code))
        ->toThrow(CouponException::class, 'Você já usou este cupom.');
});

it('lets a workspace retry a checkout it never paid with the same coupon', function () {
    $coupon = makeCoupon(['max_redemptions' => 1]);
    $tenant = couponWorkspace();
    couponSubscribe($tenant, couponPlan(10000), $coupon->code);

    $again = couponSubscribe($tenant->fresh(), couponPlan(10000), $coupon->code);

    expect($again->invoices()->first()->coupon_id)->toBe($coupon->id);
});

it('stops a plan coupon once its quota of workspaces is reached', function () {
    $coupon = makeCoupon(['max_redemptions' => 1]);
    couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);

    expect(fn () => couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code))
        ->toThrow(CouponException::class, 'Este cupom já atingiu o limite de usos.');
});

it('refuses a coupon that is off, not started, expired, for another plan or another country', function (array $attributes, string $code) {
    $plan = couponPlan(10000);
    $coupon = makeCoupon(array_merge($attributes, isset($attributes['plan_ids']) ? ['plan_ids' => [$plan->id + 1000]] : []));

    try {
        couponSubscribe(couponWorkspace(), $plan, $coupon->code);
        $this->fail('Expected the coupon to be refused.');
    } catch (CouponException $e) {
        expect($e->getErrorCode())->toBe($code);
    }
})->with([
    'inactive' => [['is_active' => false], 'coupon_inactive'],
    'not started' => [['starts_at' => now()->addDay()], 'coupon_not_started'],
    'expired' => [['ends_at' => now()->subMinute()], 'coupon_expired'],
    'other plan' => [['plan_ids' => []], 'coupon_plan'],
    'other cycle' => [['billing_cycles' => ['yearly']], 'coupon_cycle'],
    'other market' => [['market_codes' => ['ID']], 'coupon_market'],
    'top-up coupon' => [['scope' => 'topup'], 'coupon_topup_only'],
]);

it('answers an unknown code, and stops answering after too many', function () {
    $tenant = couponWorkspace();
    $plan = couponPlan(10000);

    for ($i = 0; $i < Coupons::MAX_MISSES; $i++) {
        try {
            app(PlanChange::class)->quote($tenant, $plan, couponCode: "NOPE{$i}");
        } catch (CouponException $e) {
            expect($e->getErrorCode())->toBe('coupon_not_found');
        }
    }

    $real = makeCoupon();

    expect(fn () => app(PlanChange::class)->quote($tenant, $plan, couponCode: $real->code))
        ->toThrow(CouponException::class, 'Muitas tentativas');
});

// --- Plan coupons: plan changes ------------------------------------------------

it('carries a coupon with cycles left over to an upgrade', function () {
    $coupon = makeCoupon(['percent_off' => 20, 'duration' => 'forever']);
    $tenant = couponWorkspace();
    $subscription = couponSubscribe($tenant, couponPlan(10000), $coupon->code);
    couponPay($subscription->invoices()->first());

    $quote = app(PlanChange::class)->quote($tenant->fresh(), couponPlan(30000, 'Max'), PaymentMethod::Pix);

    expect($quote['type'])->toBe(PlanChange::UPGRADE)
        ->and($quote['coupon'])->toMatchArray(['code' => $coupon->code, 'carried' => true, 'discount_cents' => 6000])
        // Coupon first (30000 → 24000), then the unused credit of the old plan.
        ->and($quote['charge_now_cents'])->toBe(24000 - $quote['discount_cents']);
});

it('does not carry a once coupon that has been used', function () {
    $coupon = makeCoupon(['duration' => 'once']);
    $tenant = couponWorkspace();
    $subscription = couponSubscribe($tenant, couponPlan(10000), $coupon->code);
    couponPay($subscription->invoices()->first());

    $quote = app(PlanChange::class)->quote($tenant->fresh(), couponPlan(30000, 'Max'), PaymentMethod::Pix);

    expect($quote['coupon'])->toBeNull();
});

it('takes no coupon on a downgrade, and keeps the running one after it', function () {
    $coupon = makeCoupon(['percent_off' => 10, 'duration' => 'forever']);
    $tenant = couponWorkspace();
    $subscription = couponSubscribe($tenant, couponPlan(30000, 'Max'), $coupon->code);
    couponPay($subscription->invoices()->first());
    $cheaper = couponPlan(10000);

    expect(fn () => app(PlanChange::class)->quote($tenant->fresh(), $cheaper, couponCode: makeCoupon()->code))
        ->toThrow(CouponException::class, 'Cupons valem apenas para um plano pago agora.');

    app(BillingService::class)->schedulePlanChange($tenant->fresh(), $cheaper);
    $renewal = app(BillingService::class)->createCycleInvoice($subscription->fresh(), PaymentMethod::Pix);

    expect($renewal->amount_cents)->toBe(9000)->and($renewal->coupon_id)->toBe($coupon->id);
});

it('never re-prices a running subscription when the coupon is edited', function () {
    $coupon = makeCoupon(['percent_off' => 10, 'duration' => 'forever']);
    $subscription = couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);

    $coupon->forceFill(['percent_off' => 50])->save();

    expect(couponNextCycle($subscription)->amount_cents)->toBe(9000);
});

// --- Top-up coupons -----------------------------------------------------------

it('pays the chosen amount and credits the bonus as its own ledger row', function () {
    $coupon = makeCoupon(['scope' => 'topup', 'percent_off' => 10]);
    $tenant = couponWorkspace();

    $invoice = app(BillingService::class)->createCreditTopupInvoice($tenant, 10000, null, $coupon->code);

    expect($invoice->amount_cents)->toBe(10000)
        ->and($invoice->coupon_bonus_cents)->toBe(1000)
        ->and($invoice->coupon_id)->toBe($coupon->id);

    couponPay($invoice);

    expect(CreditWallet::where('tenant_id', $tenant->id)->value('balance_cents'))->toBe(11000)
        ->and(CreditTransaction::where('tenant_id', $tenant->id)->pluck('type')->map->value->all())
        ->toEqualCanonicalizing([CreditTransactionType::Topup->value, CreditTransactionType::TopupBonus->value]);

    // A second notification for the same payment adds nothing.
    app(CreditService::class)->creditTopup($invoice->fresh());
    expect(CreditWallet::where('tenant_id', $tenant->id)->value('balance_cents'))->toBe(11000);
});

it('lets one workspace use a top-up coupon on as many top-ups as the quota allows', function () {
    $coupon = makeCoupon(['scope' => 'topup', 'percent_off' => 10, 'max_redemptions' => 2]);
    $tenant = couponWorkspace();
    $billing = app(BillingService::class);

    couponPay($billing->createCreditTopupInvoice($tenant, 10000, null, $coupon->code));
    $second = $billing->createCreditTopupInvoice($tenant, 5000, null, $coupon->code);

    expect($second->coupon_bonus_cents)->toBe(500);

    // The quota counts top-ups, not workspaces: the third is refused even for
    // a workspace that never used it.
    expect(fn () => $billing->createCreditTopupInvoice(couponWorkspace(), 10000, null, $coupon->code))
        ->toThrow(CouponException::class, 'Este cupom já atingiu o limite de usos.');
});

it('refuses a plan coupon on a top-up', function () {
    $coupon = makeCoupon(['scope' => 'plan']);

    expect(fn () => app(BillingService::class)->createCreditTopupInvoice(couponWorkspace(), 10000, null, $coupon->code))
        ->toThrow(CouponException::class, 'Este cupom vale apenas para planos.');
});

it('takes the bonus back with a refunded top-up', function () {
    $coupon = makeCoupon(['scope' => 'topup', 'percent_off' => 10]);
    $tenant = couponWorkspace();
    $invoice = app(BillingService::class)->createCreditTopupInvoice($tenant, 10000, null, $coupon->code);
    couponPay($invoice);

    app(CreditService::class)->reverseTopup($invoice->fresh());

    expect(CreditWallet::where('tenant_id', $tenant->id)->value('balance_cents'))->toBe(0);
});

it('checks a typed top-up coupon and quotes its bonus', function () {
    $coupon = makeCoupon(['scope' => 'topup', 'percent_off' => 25]);
    $tenant = couponWorkspace();

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/coupons/check', ['code' => strtolower($coupon->code), 'amount_cents' => 4000])
        ->assertOk()
        ->assertJsonPath('data.code', $coupon->code)
        ->assertJsonPath('data.bonus_cents', 1000);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/coupons/check', ['code' => 'NOTHING'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'coupon_not_found');
});

it('lists only the public coupons this workspace can use', function () {
    $tenant = couponWorkspace();
    $plan = couponPlan(10000);
    $listed = makeCoupon(['is_public' => true]);
    makeCoupon(['is_public' => false]);
    makeCoupon(['is_public' => true, 'is_active' => false]);
    makeCoupon(['is_public' => true, 'plan_ids' => [$plan->id + 1000]]);
    makeCoupon(['is_public' => true, 'scope' => 'topup']);

    $res = $this->actingAs($tenant->user, 'sanctum')
        ->getJson("/api/billing/coupons?scope=plan&plan_id={$plan->id}")
        ->assertOk();

    expect(collect($res->json('data'))->pluck('code')->all())->toBe([$listed->code]);
});

// --- Back Office --------------------------------------------------------------

it('creates a coupon in the Back Office and records it', function () {
    $this->actingAs(couponAdmin(), 'sanctum')
        ->postJson('/api/admin/coupons', [
            'code' => 'black20',
            'name' => 'Black Friday',
            'scope' => 'plan',
            'percent_off' => 20,
            'duration' => 'repeating',
            'duration_cycles' => 3,
            'applies_to_addons' => true,
            'is_public' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'BLACK20')
        ->assertJsonPath('data.duration_cycles', 3);

    expect(AuditLog::where('action', 'coupons.create')->exists())->toBeTrue();
});

it('forces a top-up coupon to once, with no extras and no plan limits', function () {
    $this->actingAs(couponAdmin(), 'sanctum')
        ->postJson('/api/admin/coupons', [
            'code' => 'RECARGA',
            'name' => 'Recarga',
            'scope' => 'topup',
            'percent_off' => 100,
            'duration' => 'forever',
            'applies_to_addons' => true,
            'plan_ids' => [couponPlan()->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.duration', 'once')
        ->assertJsonPath('data.applies_to_addons', false)
        ->assertJsonPath('data.plan_ids', null);
});

it('caps a plan coupon at 90 percent', function () {
    $this->actingAs(couponAdmin(), 'sanctum')
        ->postJson('/api/admin/coupons', ['code' => 'FREE', 'name' => 'Free', 'scope' => 'plan', 'percent_off' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors('percent_off');
});

it('locks what was promised once a charge has carried the coupon', function () {
    $coupon = makeCoupon(['percent_off' => 10]);
    couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);
    $admin = couponAdmin();

    $payload = [
        'code' => $coupon->code,
        'name' => 'Renamed',
        'scope' => 'plan',
        'percent_off' => 10,
        'duration' => 'once',
        'is_active' => false,
    ];

    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/coupons/{$coupon->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($admin, 'sanctum')->putJson("/api/admin/coupons/{$coupon->id}", ['percent_off' => 30] + $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('percent_off');

    $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/coupons/{$coupon->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'coupon_in_use');
});

it('reports usage per currency in the Back Office list', function () {
    $coupon = makeCoupon(['percent_off' => 10]);
    $subscription = couponSubscribe(couponWorkspace(), couponPlan(10000), $coupon->code);
    couponPay($subscription->invoices()->first());

    $res = $this->actingAs(couponAdmin(), 'sanctum')->getJson('/api/admin/coupons')->assertOk();

    expect($res->json('data.0.usage'))->toMatchArray([
        'redemptions' => 1,
        'paid_charges' => 1,
        'locked' => true,
        'given' => [['currency' => 'BRL', 'discount_cents' => 1000, 'bonus_cents' => 0]],
    ]);
});
