<?php

use App\Enums\Affiliate\CommissionStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Models\Admin;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Affiliates\AffiliateLinks;
use App\Services\Affiliates\AffiliateSettings;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Affiliates: a link locks a visitor to an affiliator until they sign up, the
 * workspace they create stays locked to them, and every paid plan charge of
 * that workspace (plan + extras, after coupons) earns a percentage — once or
 * recurring for N months / forever. Paid by hand after a holding period.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['services.billing.payment_method' => 'direct', 'affiliates.landing_url' => 'https://pingly.com.br']);
    Role::findOrCreate('owner', 'web');

    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Setting::set(DirectBillingConfig::MP_PUBLIC_KEY, 'APP_USR-public');

    Http::fake(['api.mercadopago.com/v1/payments' => function () {
        static $n = 0;
        $n++;

        return Http::response([
            'id' => 9000 + $n,
            'status' => 'pending',
            'external_reference' => 'ref',
            'date_of_expiration' => now()->addDay()->toIso8601String(),
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126PIX', 'qr_code_base64' => 'B64']],
        ]);
    }]);
});

function affWorkspace(?string $whatsapp = null): Tenant
{
    $user = User::factory()->create([
        'email' => 'aff-'.uniqid().'@example.test',
        'whatsapp_number' => $whatsapp ?? '55119'.random_int(10000000, 99999999),
    ]);
    $user->forceFill(['whatsapp_verified_at' => now()])->save();

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = 'BR';
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $tenant->forceFill([
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ])->save();

    return $tenant->fresh();
}

function affAffiliate(array $attributes = []): Affiliate
{
    $owner = affWorkspace();

    return Affiliate::create(array_merge([
        'user_id' => $owner->user_id,
        'code' => 'ana-'.Str::lower(Str::random(4)),
        'is_active' => true,
        'commission_percent' => 20,
        'commission_type' => 'recurring',
    ], $attributes));
}

/** A workspace brought in by the affiliator. */
function affReferred(Affiliate $affiliate): Tenant
{
    $tenant = affWorkspace();
    app(AffiliateLinks::class)->attach($tenant, $affiliate);

    return $tenant->fresh();
}

function affPlan(int $cents = 10000): Plan
{
    return Plan::create([
        'name' => 'Pro',
        'slug' => 'aff-'.uniqid(),
        'price_cents' => $cents,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'trial_days' => 0,
        'is_active' => true,
        'is_public' => true,
    ])->fresh();
}

function affPay(Invoice $invoice): void
{
    app(BillingService::class)->applyPaymentUpdate([
        'id' => $invoice->payment_id,
        'order_reference' => $invoice->order_reference,
        'status' => 'paid',
    ], 'mercadopago');
}

/** Subscribe by Pix and pay the first charge. */
function affSubscribeAndPay(Tenant $tenant, ?Plan $plan = null, ?string $coupon = null): Subscription
{
    $subscription = app(BillingService::class)->subscribe($tenant, $plan ?? affPlan(), PaymentMethod::Pix, array_filter([
        'payer_email' => 'ana@example.test',
        'coupon_code' => $coupon,
    ]));
    affPay($subscription->invoices()->first());

    return $subscription->fresh();
}

function affRenewAndPay(Subscription $subscription): Invoice
{
    $invoice = app(BillingService::class)->createCycleInvoice($subscription->fresh(), PaymentMethod::Pix);
    affPay($invoice);

    return $invoice->fresh();
}

function affAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.affiliates.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

function affRegister($test, array $extra = [])
{
    return $test->postJson('/api/auth/register', array_merge([
        'name' => 'Nova Empresa',
        'email' => 'nova-'.uniqid().'@example.com',
        'password' => 'supersecret-123',
        'password_confirmation' => 'supersecret-123',
        'whatsapp_number' => '+55119'.random_int(10000000, 99999999),
    ], $extra));
}

// --- The link ----------------------------------------------------------------

it('locks the visitor with a cookie and sends them to the landing page', function () {
    $affiliate = affAffiliate(['code' => 'ana']);

    $this->get('/r/ANA')
        ->assertRedirect('https://pingly.com.br')
        ->assertPlainCookie(AffiliateLinks::COOKIE, 'ana');

    expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(1);

    // The same visitor again the same day is one visitor.
    $this->get('/r/ana');
    expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(1);
});

it('keeps the first affiliator when the visitor opens another one\'s link', function () {
    affAffiliate(['code' => 'ana']);
    affAffiliate(['code' => 'bruno']);

    $this->withUnencryptedCookie(AffiliateLinks::COOKIE, 'ana')
        ->get('/r/bruno')
        ->assertRedirect('https://pingly.com.br')
        ->assertCookieMissing(AffiliateLinks::COOKIE);
});

it('sets nothing for an unknown or switched-off code', function () {
    affAffiliate(['code' => 'off', 'is_active' => false]);

    $this->get('/r/off')->assertRedirect('https://pingly.com.br')->assertCookieMissing(AffiliateLinks::COOKIE);
    $this->get('/r/nobody')->assertRedirect('https://pingly.com.br')->assertCookieMissing(AffiliateLinks::COOKIE);
});

// --- Signup ------------------------------------------------------------------

it('locks the new workspace to the affiliator from the cookie', function () {
    $affiliate = affAffiliate(['code' => 'ana']);

    $response = affRegister($this->withCredentials()->withUnencryptedCookie(AffiliateLinks::COOKIE, 'ana'))->assertCreated();

    $tenant = User::where('email', $response->json('user.email'))->first()->tenant;

    expect($tenant->referred_by_affiliate_id)->toBe($affiliate->id)
        ->and($tenant->referred_at)->not->toBeNull();
});

it('takes the code the sign-up page carried when there is no cookie, and the cookie when both', function () {
    $ana = affAffiliate(['code' => 'ana']);
    $bruno = affAffiliate(['code' => 'bruno']);

    $first = affRegister($this, ['ref' => 'bruno'])->assertCreated();
    expect(User::where('email', $first->json('user.email'))->first()->tenant->referred_by_affiliate_id)->toBe($bruno->id);

    $second = affRegister($this->withCredentials()->withUnencryptedCookie(AffiliateLinks::COOKIE, 'ana'), ['ref' => 'bruno'])->assertCreated();
    expect(User::where('email', $second->json('user.email'))->first()->tenant->referred_by_affiliate_id)->toBe($ana->id);
});

it('never locks the affiliator\'s own number to themselves', function () {
    $affiliate = affAffiliate(['code' => 'ana']);
    $number = $affiliate->user->whatsapp_number;

    $response = affRegister($this->withCredentials()->withUnencryptedCookie(AffiliateLinks::COOKIE, 'ana'), ['whatsapp_number' => '+'.$number])->assertCreated();

    expect(User::where('email', $response->json('user.email'))->first()->tenant->referred_by_affiliate_id)->toBeNull();
});

// --- Commissions -------------------------------------------------------------

it('earns a percentage of what was actually paid, held for the holding period', function () {
    AffiliateSettings::setHoldDays(30);
    $affiliate = affAffiliate(['commission_percent' => 20]);
    $tenant = affReferred($affiliate);
    Coupon::create(['code' => 'DEZ', 'name' => 'Dez', 'scope' => 'plan', 'percent_off' => 10, 'duration' => 'once', 'is_active' => true]);

    affSubscribeAndPay($tenant, affPlan(10000), 'DEZ');

    $commission = AffiliateCommission::where('affiliate_id', $affiliate->id)->sole();

    // 10000 − 10% coupon = 9000 paid; 20% of it.
    expect($commission->base_cents)->toBe(9000)
        ->and($commission->amount_cents)->toBe(1800)
        ->and($commission->status)->toBe(CommissionStatus::Pending)
        ->and($commission->available_at->isAfter(now()->addDays(29)))->toBeTrue();
});

it('pays a once affiliator on the first charge only', function () {
    $affiliate = affAffiliate(['commission_type' => 'once']);
    $subscription = affSubscribeAndPay(affReferred($affiliate));

    affRenewAndPay($subscription);

    expect(AffiliateCommission::where('affiliate_id', $affiliate->id)->count())->toBe(1);
});

it('pays a recurring affiliator on every charge, until its months run out', function () {
    $affiliate = affAffiliate(['commission_type' => 'recurring', 'recurring_months' => 2]);
    $subscription = affSubscribeAndPay(affReferred($affiliate));

    $this->travel(1)->months();
    affRenewAndPay($subscription);
    expect(AffiliateCommission::where('affiliate_id', $affiliate->id)->count())->toBe(2);

    $this->travel(1)->months();
    affRenewAndPay($subscription);
    expect(AffiliateCommission::where('affiliate_id', $affiliate->id)->count())->toBe(2);
});

it('keeps paying a recurring affiliator with no month limit', function () {
    $affiliate = affAffiliate(['commission_type' => 'recurring', 'recurring_months' => null]);
    $subscription = affSubscribeAndPay(affReferred($affiliate));

    foreach (range(1, 3) as $month) {
        $this->travel(1)->months();
        affRenewAndPay($subscription);
    }

    expect(AffiliateCommission::where('affiliate_id', $affiliate->id)->count())->toBe(4);
});

it('earns nothing on a balance top-up, nor for a switched-off affiliator', function () {
    $affiliate = affAffiliate();
    $tenant = affReferred($affiliate);

    affPay(app(BillingService::class)->createCreditTopupInvoice($tenant, 10000));
    expect(AffiliateCommission::count())->toBe(0);

    $affiliate->update(['is_active' => false]);
    affSubscribeAndPay($tenant);
    expect(AffiliateCommission::count())->toBe(0);
});

it('records one commission when the same payment is notified twice', function () {
    $affiliate = affAffiliate();
    $subscription = affSubscribeAndPay(affReferred($affiliate));

    affPay($subscription->invoices()->first());
    app(\App\Services\Affiliates\AffiliateCommissions::class)->onInvoicePaid($subscription->invoices()->first());

    expect(AffiliateCommission::count())->toBe(1);
});

// --- Refunds and payouts -------------------------------------------------------

it('reverses a commission refunded before payout, and claws back one already paid', function () {
    AffiliateSettings::setHoldDays(0);
    $affiliate = affAffiliate(['commission_percent' => 10]);
    $subscription = affSubscribeAndPay(affReferred($affiliate), affPlan(10000));
    $first = $subscription->invoices()->first();

    // Paid out, then the charge is refunded: the next payout owes it back.
    app(\App\Services\Affiliates\AffiliateCommissions::class)->recordPayout($affiliate, 'BRL', 'pix', 'E2E1', null, null);
    $first->update(['status' => InvoiceStatus::Refunded]);

    $clawback = AffiliateCommission::whereNotNull('reversal_of_id')->sole();
    expect($clawback->amount_cents)->toBe(-1000);

    // A second charge, refunded before any payout, is simply reversed.
    $this->travel(1)->months();
    $second = affRenewAndPay($subscription);
    $second->update(['status' => InvoiceStatus::Refunded]);

    expect(AffiliateCommission::where('invoice_id', $second->id)->sole()->status)->toBe(CommissionStatus::Reversed);
});

it('pays only what is past its holding period, net of clawbacks', function () {
    AffiliateSettings::setHoldDays(30);
    $affiliate = affAffiliate(['commission_percent' => 10]);
    $subscription = affSubscribeAndPay(affReferred($affiliate), affPlan(10000));
    $admin = affAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/affiliates/{$affiliate->id}/payouts", ['currency' => 'BRL', 'method' => 'pix'])
        ->assertStatus(422);

    $this->travel(31)->days();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/affiliates/{$affiliate->id}/payouts", ['currency' => 'BRL', 'method' => 'pix', 'reference' => 'E2E-123'])
        ->assertCreated()
        ->assertJsonPath('data.amount_cents', 1000);

    expect(AffiliateCommission::sole()->status)->toBe(CommissionStatus::Paid)
        ->and(AuditLog::where('action', 'affiliates.payout')->exists())->toBeTrue();
});

// --- The affiliator's page -----------------------------------------------------

it('shows the affiliator their link, numbers and balances, and nobody else', function () {
    $affiliate = affAffiliate(['code' => 'ana', 'commission_percent' => 20]);
    affSubscribeAndPay(affReferred($affiliate), affPlan(10000));
    $this->get('/r/ana');

    $this->actingAs($affiliate->user, 'sanctum')
        ->getJson('/api/user/affiliate')
        ->assertOk()
        ->assertJsonPath('data.code', 'ana')
        ->assertJsonPath('data.stats', ['clicks' => 1, 'signups' => 1, 'customers' => 1])
        ->assertJsonPath('data.balances.0.holding_cents', 2000)
        ->assertJsonPath('data.commissions.0.customer', 'Ana D.');

    $this->actingAs($affiliate->user, 'sanctum')->getJson('/api/user')->assertJsonPath('data.affiliate.code', 'ana');

    $stranger = affWorkspace()->user;
    $this->actingAs($stranger, 'sanctum')->getJson('/api/user/affiliate')->assertNotFound();
    $this->actingAs($stranger, 'sanctum')->getJson('/api/user')->assertJsonPath('data.affiliate', null);
});

it('lets the affiliator say where to be paid', function () {
    $affiliate = affAffiliate();

    $this->actingAs($affiliate->user, 'sanctum')
        ->putJson('/api/user/affiliate/payout-details', ['holder_name' => ' Ana Duarte ', 'pix_key' => 'ana@example.test'])
        ->assertOk()
        ->assertJsonPath('data.holder_name', 'Ana Duarte');
});

// --- Back Office -------------------------------------------------------------

it('turns a user into an affiliator with a readable code, and refuses a second time', function () {
    $user = affWorkspace()->user;
    $user->update(['name' => 'Bruno Lima']);
    $admin = affAdmin();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/affiliates', [
            'user_id' => $user->id,
            'commission_percent' => 25,
            'commission_type' => 'recurring',
            'recurring_months' => 12,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'bruno-lima')
        ->assertJsonPath('data.recurring_months', 12);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/affiliates', ['user_id' => $user->id, 'commission_percent' => 25, 'commission_type' => 'once'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(AuditLog::where('action', 'affiliates.create')->exists())->toBeTrue();
});

it('offers and accepts only workspace owners, never their agents', function () {
    $tenant = affWorkspace();
    $owner = $tenant->user;
    $owner->update(['name' => 'Owner Person']);

    $agent = User::factory()->create(['name' => 'Agent Person', 'tenant_id' => $tenant->id]);

    // Holding the owner role makes a second person an owner too.
    Role::findOrCreate('owner', 'web');
    $coOwner = User::factory()->create(['name' => 'Co Owner', 'tenant_id' => $tenant->id]);
    $coOwner->assignRole('owner');

    $admin = affAdmin();

    $ids = collect($this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/affiliates/candidates?search=Person')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($owner->id)->not->toContain($agent->id);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/affiliates/candidates?search=Co%20Owner')
        ->assertOk()
        ->assertJsonPath('data.0.id', $coOwner->id);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/affiliates', ['user_id' => $agent->id, 'commission_percent' => 20, 'commission_type' => 'once'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(Affiliate::where('user_id', $agent->id)->exists())->toBeFalse();
});

it('drops the month limit on a once affiliator and refuses to delete one with referrals', function () {
    $affiliate = affAffiliate(['code' => 'ana']);
    affReferred($affiliate);
    $admin = affAdmin();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/admin/affiliates/{$affiliate->id}", [
            'code' => 'ANA',
            'commission_percent' => 30,
            'commission_type' => 'once',
            'recurring_months' => 6,
        ])
        ->assertOk()
        ->assertJsonPath('data.recurring_months', null)
        ->assertJsonPath('data.commission_percent', 30);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/admin/affiliates/{$affiliate->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'affiliate_in_use');
});

it('saves the holding period', function () {
    $this->actingAs(affAdmin(), 'sanctum')
        ->putJson('/api/admin/affiliates/settings', ['hold_days' => 14])
        ->assertOk()
        ->assertJsonPath('data.hold_days', 14);

    expect(AffiliateSettings::holdDays())->toBe(14);
});
