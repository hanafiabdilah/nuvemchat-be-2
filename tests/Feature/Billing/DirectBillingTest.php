<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Market;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\PaymentService\PaymentServiceClient;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
 * PAYMENT_METHOD=direct: Pingly bills through its own gateway accounts —
 * Mercado Pago for the Brazilian market, dLocal Go for every other one —
 * instead of the payment service. What these pin down is the part that is easy
 * to get subtly wrong: the right company for each market, a preapproval that is
 * never billed twice for its first cycle, and an existing charge that keeps
 * talking to the gateway it was made on after the env is flipped.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['services.billing.payment_method' => 'direct']);

    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Setting::set(DirectBillingConfig::MP_PUBLIC_KEY, 'APP_USR-public');
    Setting::set(DirectBillingConfig::DLOCALGO_API_KEY, 'go_api');
    Setting::set(DirectBillingConfig::DLOCALGO_SECRET_KEY, 'go_secret');
});

function directWorkspace(string $marketCode = 'BR'): Tenant
{
    if ($marketCode !== 'BR' && ! Market::whereKey($marketCode)->exists()) {
        Market::create([
            'code' => $marketCode,
            'name' => 'Mexico',
            'currency' => 'MXN',
            'default_locale' => 'en',
            'default_timezone' => 'America/Mexico_City',
            'phone_country' => '52',
            'status' => 'active',
        ]);
    }

    $user = User::factory()->create(['email' => 'direct-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $tenant->forceFill([
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => $marketCode === 'BR' ? 'CPF' : 'TAX_ID',
        'billing_document_number' => $marketCode === 'BR' ? '12345678909' : 'XAXX010101000',
    ])->save();

    return $tenant->fresh();
}

function directPlan(): Plan
{
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 9990,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
        'is_public' => true,
    ]);

    if (Market::whereKey('MX')->exists()) {
        $plan->syncMarketPrices(['BR' => 9990, 'MX' => 49900]);
    }

    return $plan->fresh();
}

// --- Which company takes the money ---------------------------------------

it('bills Brazil through Mercado Pago and every other market through dLocal Go', function () {
    $gateways = app(BillingGateways::class);

    expect($gateways->forTenant(directWorkspace('BR'))->name())->toBe('mercadopago')
        ->and($gateways->forTenant(directWorkspace('MX'))->name())->toBe('dlocalgo');
});

it('keeps using the payment service while the env says so', function () {
    config(['services.billing.payment_method' => 'payment_service']);

    expect(app(BillingGateways::class)->forTenant(directWorkspace('MX')))->toBeInstanceOf(PaymentServiceClient::class);
});

it('treats an unknown PAYMENT_METHOD as the payment service rather than guessing', function () {
    config(['services.billing.payment_method' => 'stripe']);

    expect(app(BillingGateways::class)->forTenant(directWorkspace('BR')))->toBeInstanceOf(PaymentServiceClient::class);
});

it('offers card and Pix in Brazil and a hosted checkout elsewhere, with no plan checkbox involved', function () {
    $billing = app(BillingService::class);

    expect($billing->offeredMethods(directWorkspace('BR')))->toBe(['card', 'pix'])
        ->and($billing->offeredMethods(directWorkspace('MX')))->toBe(['checkout']);
});

it('ignores the old per-plan Pix checkbox', function () {
    Http::fake(['api.mercadopago.com/v1/payments' => Http::response(mpPixPayment())]);

    $plan = directPlan();
    $plan->marketPrices()->update(['pix_enabled' => false]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('BR'), $plan->fresh(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    expect($subscription->invoices()->first()->pix_qr_code)->toBe('00020126PIX');
});

// --- Brazil: Pix -----------------------------------------------------------

function mpPixPayment(array $overrides = []): array
{
    return array_merge([
        'id' => 123456789,
        'status' => 'pending',
        'status_detail' => 'pending_waiting_transfer',
        'external_reference' => 'ref',
        'date_of_expiration' => now()->addDay()->toIso8601String(),
        'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126PIX', 'qr_code_base64' => 'B64']],
    ], $overrides);
}

it('sends a Brazilian Pix straight to Mercado Pago in its own decimal terms', function () {
    Http::fake(['api.mercadopago.com/v1/payments' => Http::response(mpPixPayment())]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(function ($request) use ($subscription) {
        $body = $request->data();

        return $request->hasHeader('X-Idempotency-Key')
            && $body['transaction_amount'] === 99.9
            && $body['payment_method_id'] === 'pix'
            && $body['external_reference'] === "pingly-sub-{$subscription->id}-".now()->toDateString()
            && $body['payer']['identification'] === ['type' => 'CPF', 'number' => '12345678909'];
    });

    $invoice = $subscription->invoices()->first();

    expect($invoice->gateway)->toBe('mercadopago')
        ->and($invoice->payment_id)->toBe('123456789')
        ->and($invoice->pix_copy_paste)->toBe('00020126PIX')
        ->and($invoice->pix_qr_code_base64)->toBe('data:image/png;base64,B64')
        ->and($subscription->fresh()->gateway)->toBe('mercadopago');
});

it('activates the plan when the Mercado Pago webhook says the Pix was paid', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments' => Http::response(mpPixPayment()),
        'api.mercadopago.com/v1/payments/123456789' => Http::response(mpPixPayment(['status' => 'approved'])),
    ]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    $this->postJson('/webhook/billing/mercadopago?type=payment&data.id=123456789', [
        'type' => 'payment',
        'data' => ['id' => '123456789'],
    ])->assertOk();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Paid);
});

it('refuses a Mercado Pago notification whose signature does not match', function () {
    Setting::set(DirectBillingConfig::MP_WEBHOOK_SECRET, 'mp_secret');
    Http::fake(['api.mercadopago.com/v1/payments' => Http::response(mpPixPayment())]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    $this->postJson('/webhook/billing/mercadopago?type=payment&data.id=123456789', ['type' => 'payment', 'data' => ['id' => '123456789']], [
        'x-signature' => 'ts=1700000000,v1=deadbeef',
        'x-request-id' => 'req-1',
    ])->assertOk()->assertJson(['status' => 'invalid-signature']);

    // Nothing was read back, so nothing moved.
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/payments/123456789'));
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

// --- Brazil: card = preapproval --------------------------------------------

it('subscribes a Brazilian card as a Mercado Pago preapproval that renews itself', function () {
    Http::fake(['api.mercadopago.com/preapproval' => Http::response(['id' => 'pre_1', 'status' => 'authorized'])]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Card, [
        'card_token' => 'tok_1',
        'provider' => 'mercadopago',
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(function ($request) use ($subscription) {
        $body = $request->data();

        return str_ends_with($request->url(), '/preapproval')
            && $body['card_token_id'] === 'tok_1'
            && $body['status'] === 'authorized'
            && $body['external_reference'] === "pingly-mpsub-{$subscription->id}"
            && $body['auto_recurring'] === [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => 99.9,
                'currency_id' => 'BRL',
            ];
    });

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->gateway)->toBe('mercadopago')
        ->and($subscription->payment_instrument_id)->toBe('mp_preapproval:pre_1');

    // billing:charge-renewals must never charge it — Mercado Pago does.
    $subscription->update(['current_period_end' => now()->addDay()]);
    expect(app(BillingService::class)->chargeRenewal($subscription->fresh()))->toBeNull();
});

it('attaches the first preapproval debit to the first invoice and bills later ones as renewals', function () {
    Http::fake(['api.mercadopago.com/preapproval' => Http::response(['id' => 'pre_1', 'status' => 'authorized'])]);

    $billing = app(BillingService::class);
    $subscription = $billing->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Card, [
        'card_token' => 'tok_1',
        'payer_email' => 'ana@example.test',
    ]);
    $firstEnd = $subscription->fresh()->current_period_end;

    // First debit: joins the invoice subscribe() already created.
    $billing->applyRecurringCharge('mercadopago', 'mp_preapproval:pre_1', 'pay_1', 'paid');

    expect(Invoice::count())->toBe(1)
        ->and(Invoice::first()->payment_id)->toBe('pay_1')
        ->and($subscription->fresh()->current_period_end->equalTo($firstEnd))->toBeTrue();

    // A redelivery of it changes nothing.
    $billing->applyRecurringCharge('mercadopago', 'mp_preapproval:pre_1', 'pay_1', 'paid');
    expect(Invoice::count())->toBe(1);

    // The next cycle: a new paid invoice and one more month.
    $billing->applyRecurringCharge('mercadopago', 'mp_preapproval:pre_1', 'pay_2', 'paid');

    expect(Invoice::count())->toBe(2)
        ->and($subscription->fresh()->current_period_end->equalTo($firstEnd->copy()->addMonth()))->toBeTrue();

    // A refused cycle bills nothing.
    $billing->applyRecurringCharge('mercadopago', 'mp_preapproval:pre_1', 'pay_3', 'rejected');
    expect(Invoice::count())->toBe(2);
});

it('reads a preapproval cycle from the authorized-payment webhook', function () {
    Http::fake([
        'api.mercadopago.com/preapproval' => Http::response(['id' => 'pre_1', 'status' => 'authorized']),
        'api.mercadopago.com/authorized_payments/777' => Http::response([
            'id' => 777,
            'preapproval_id' => 'pre_1',
            'status' => 'processed',
            'payment' => ['id' => 5551, 'status' => 'approved'],
        ]),
    ]);

    app(BillingService::class)->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Card, [
        'card_token' => 'tok_1',
        'payer_email' => 'ana@example.test',
    ]);

    $this->postJson('/webhook/billing/mercadopago?type=subscription_authorized_payment&data.id=777', [
        'type' => 'subscription_authorized_payment',
        'data' => ['id' => '777'],
    ])->assertOk();

    expect(Invoice::first()->payment_id)->toBe('5551');
});

it('pauses the preapproval on cancel, re-authorises it on resume, and ends it on suspend', function () {
    Http::fake([
        'api.mercadopago.com/preapproval' => Http::response(['id' => 'pre_1', 'status' => 'authorized']),
        'api.mercadopago.com/preapproval/pre_1' => Http::response(['id' => 'pre_1']),
    ]);

    $billing = app(BillingService::class);
    $subscription = $billing->subscribe(directWorkspace('BR'), directPlan(), PaymentMethod::Card, [
        'card_token' => 'tok_1',
        'payer_email' => 'ana@example.test',
    ]);

    $billing->cancel($subscription->fresh());
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['status' => 'paused']);

    $billing->resume($subscription->fresh());
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['status' => 'authorized']);

    $billing->suspend($subscription->fresh());
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['status' => 'cancelled']);
});

// --- Every other market: dLocal Go hosted checkout -------------------------

function goPayment(array $overrides = []): array
{
    return array_merge([
        'id' => 'DP-1',
        'status' => 'PENDING',
        'order_id' => 'ref',
        'redirect_url' => 'https://checkout.dlocalgo.com/validate/DP-1',
    ], $overrides);
}

function goSignature(string $body): string
{
    return 'V2-HMAC-SHA256, Signature: '.hash_hmac('sha256', 'go_api'.$body, 'go_secret');
}

it('sends a non-Brazilian subscription to a dLocal Go hosted checkout', function () {
    Http::fake(['api.dlocalgo.com/v1/payments' => Http::response(goPayment())]);

    $tenant = directWorkspace('MX');
    $subscription = app(BillingService::class)->subscribe($tenant, directPlan(), PaymentMethod::Checkout, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(function ($request) use ($subscription) {
        $body = $request->data();

        return $request->header('Authorization')[0] === 'Bearer go_api:go_secret'
            && $body['amount'] === 499.0
            && $body['currency'] === 'MXN'
            && $body['country'] === 'MX'
            && $body['order_id'] === "pingly-sub-{$subscription->id}-".now()->toDateString()
            && str_ends_with($body['success_url'], '/billing');
    });

    $invoice = $subscription->invoices()->first();

    expect($invoice->payment_method)->toBe(PaymentMethod::Checkout)
        ->and($invoice->gateway)->toBe('dlocalgo')
        ->and($invoice->checkout_url)->toBe('https://checkout.dlocalgo.com/validate/DP-1')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('refuses Pix outside Brazil even in direct mode', function () {
    expect(fn () => app(BillingService::class)->subscribe(directWorkspace('MX'), directPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('activates the plan on a signed dLocal Go notification, read back from their API', function () {
    Http::fake([
        'api.dlocalgo.com/v1/payments' => Http::response(goPayment()),
        'api.dlocalgo.com/v1/payments/DP-1' => Http::response(goPayment(['status' => 'PAID'])),
    ]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('MX'), directPlan(), PaymentMethod::Checkout, [
        'payer_email' => 'ana@example.test',
    ]);

    $body = json_encode(['payment_id' => 'DP-1']);

    $this->call('POST', '/webhook/billing/dlocalgo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => goSignature($body),
    ], $body)->assertOk()->assertJson(['status' => 'ok']);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Paid);
});

it('refuses an unsigned dLocal Go notification', function () {
    Http::fake(['api.dlocalgo.com/v1/payments' => Http::response(goPayment())]);

    $subscription = app(BillingService::class)->subscribe(directWorkspace('MX'), directPlan(), PaymentMethod::Checkout, [
        'payer_email' => 'ana@example.test',
    ]);

    $this->postJson('/webhook/billing/dlocalgo', ['payment_id' => 'DP-1'], ['Authorization' => 'V2-HMAC-SHA256, Signature: 00'])
        ->assertOk()->assertJson(['status' => 'invalid-signature']);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('tops up a non-Brazilian balance through the hosted checkout', function () {
    Http::fake(['api.dlocalgo.com/v1/payments' => Http::response(goPayment())]);

    $invoice = app(BillingService::class)->createCreditTopupInvoice(directWorkspace('MX'), 50000);

    expect($invoice->purpose)->toBe(InvoicePurpose::CreditTopup)
        ->and($invoice->payment_method)->toBe(PaymentMethod::Checkout)
        ->and($invoice->checkout_url)->toBe('https://checkout.dlocalgo.com/validate/DP-1');
});

it('issues the next cycle of a checkout subscription as another checkout link', function () {
    Http::fake(['api.dlocalgo.com/v1/payments' => Http::response(goPayment())]);

    $billing = app(BillingService::class);
    $subscription = $billing->subscribe(directWorkspace('MX'), directPlan(), PaymentMethod::Checkout, [
        'payer_email' => 'ana@example.test',
    ]);

    // Paid, and now near the end of its period.
    $subscription->invoices()->update(['status' => InvoiceStatus::Paid->value]);
    $subscription->update(['status' => SubscriptionStatus::Active, 'current_period_end' => now()->addDay()]);

    Artisan::call('billing:pix-generate', ['--days-before' => 3]);

    expect($subscription->invoices()->where('status', InvoiceStatus::Pending->value)->first()?->payment_method)
        ->toBe(PaymentMethod::Checkout);
});

// --- Flipping the env never orphans what exists -----------------------------

it('keeps renewing a card stored at the payment service after switching to direct', function () {
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
    Http::fake(['*/payments' => Http::response(['data' => ['id' => 'ps_9', 'status' => 'paid', 'order_reference' => 'x']])]);

    $tenant = directWorkspace('BR');
    $plan = directPlan();

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Card,
        'billing_cycle' => 'monthly',
        'price_cents' => 9990,
        'currency' => 'BRL',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
        'payment_instrument_id' => 'inst_1',
    ]);

    $invoice = app(BillingService::class)->chargeRenewal($subscription);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'gateway.proxybr.com.br')
        && $request->data()['instrument_id'] === 'inst_1');
    expect($invoice->gateway)->toBe('payment_service')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

it('reports the direct gateways to the Back Office and saves their secrets', function () {
    $role = \Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bo.settings.manage', 'web'));
    $admin = \App\Models\Admin::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('data.billing_mode', 'direct')
        ->assertJsonPath('data.billing_direct.mercadopago.public_key', 'APP_USR-public')
        ->assertJsonPath('data.billing_direct.mercadopago.access_token_set', true)
        ->assertJsonMissingPath('data.billing_direct.mercadopago.access_token')
        ->assertJsonPath('data.billing_direct.dlocalgo.secret_key_set', true);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', [
            'billing_direct' => [
                'dlocalgo' => ['api_key' => 'new_api', 'secret_key' => '', 'sandbox' => true],
            ],
        ])
        ->assertOk();

    // A blank secret keeps the stored one: saving the sandbox switch alone
    // must not wipe the key that takes money.
    expect(DirectBillingConfig::dlocalGoApiKey())->toBe('new_api')
        ->and(DirectBillingConfig::dlocalGoSecretKey())->toBe('go_secret')
        ->and(DirectBillingConfig::dlocalGoBaseUrl())->toBe('https://api-sbx.dlocalgo.com');
});

it('checks dLocal Go credentials with a GET, which is the only verb /v1/me answers', function () {
    Http::fake(['api.dlocalgo.com/v1/me' => fn ($request) => $request->method() === 'GET'
        ? Http::response(['merchant_id' => 235256, 'currency' => 'BRL'])
        : Http::response(['code' => 7000, 'message' => 'internal_server_error'], 500)]);

    $role = \Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bo.settings.manage', 'web'));
    $admin = \App\Models\Admin::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/billing-gateways/dlocalgo/test')
        ->assertOk()
        ->assertJsonPath('data.account', 'Merchant #235256 (BRL)')
        ->assertJsonPath('data.methods', ['checkout']);
});

// --- Every other market: dLocal Go card on our page (SmartFields) -----------

function goCardFakes(array $confirm = [], array $recurring = []): void
{
    Http::fake([
        'api.dlocalgo.com/v1/payments/confirm/*' => Http::response(array_merge(['id' => 'DP-7', 'status' => 'PAID'], $confirm)),
        'api.dlocalgo.com/v1/payments/recurring/*' => Http::response(array_merge(['id' => 'DP-8', 'status' => 'PAID'], $recurring)),
        'api.dlocalgo.com/v1/payments/DP-7' => Http::response(['id' => 'DP-7', 'status' => 'PAID']),
        'api.dlocalgo.com/v1/payments' => Http::response([
            'id' => 'DP-7',
            'status' => 'PENDING',
            'redirect_url' => 'https://checkout.dlocalgo.com/validate/tok_abc',
            'merchant_checkout_token' => 'tok_abc',
        ]),
    ]);
}

it('offers a card of its own outside Brazil only once the SmartFields key is set', function () {
    $tenant = directWorkspace('MX');
    $billing = app(BillingService::class);

    expect($billing->offeredMethods($tenant))->toBe(['checkout']);

    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    \Illuminate\Support\Facades\Cache::flush();

    expect($billing->offeredMethods($tenant))->toBe(['card', 'checkout'])
        ->and($billing->cardSession($tenant))->toMatchArray([
            'sdk' => 'dlocalgo',
            'public_key' => 'sf_key',
            'provider' => 'dlocalgo',
            'requires_checkout' => true,
            'sdk_url' => DirectBillingConfig::DLOCALGO_SMARTFIELDS_PRODUCTION_SDK,
        ]);
});

it('opens the card payment before the form renders, without touching the live subscription', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes();

    $tenant = directWorkspace('MX');
    $opened = app(BillingService::class)->openCardCheckout($tenant, directPlan(), 'ana@example.test');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.dlocalgo.com/v1/payments'
        && $request->data()['allow_transparent'] === true
        && $request->data()['allow_recurring'] === true
        && $request->data()['amount'] === 499.0
        && $request->data()['currency'] === 'MXN');

    expect($opened['checkout_token'])->toBe('tok_abc')
        ->and(Subscription::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(Invoice::where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($tenant->fresh()->current_subscription_id)->toBeNull();
});

it('confirms the card on our page and keeps it for the cycles after this one', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes();

    $tenant = directWorkspace('MX');
    $plan = directPlan();
    $billing = app(BillingService::class);
    $opened = $billing->openCardCheckout($tenant, $plan, 'ana@example.test');

    $subscription = $billing->subscribe($tenant, $plan, PaymentMethod::Card, [
        'card_token' => 'card_tok_1',
        'checkout_token' => $opened['checkout_token'],
        'provider' => 'dlocalgo',
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.dlocalgo.com/v1/payments/confirm/tok_abc'
        && $request->data()['cardToken'] === 'card_tok_1'
        && $request->data()['clientFirstName'] === 'Ana'
        && $request->data()['clientLastName'] === 'Duarte'
        && $request->data()['clientEmail'] === 'ana@example.test');

    $invoice = $subscription->invoices()->first();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->gateway)->toBe('dlocalgo')
        ->and($subscription->payment_instrument_id)->toBe('dlgo_rec:tok_abc')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payment_id)->toBe('DP-7')
        ->and($invoice->order_reference)->toStartWith("pingly-card-{$tenant->id}-");
});

it('renews that card every cycle from our scheduler, with nobody at a screen', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes();

    $tenant = directWorkspace('MX');
    $plan = directPlan();
    $billing = app(BillingService::class);
    $opened = $billing->openCardCheckout($tenant, $plan);
    $subscription = $billing->subscribe($tenant, $plan, PaymentMethod::Card, [
        'card_token' => 'card_tok_1',
        'checkout_token' => $opened['checkout_token'],
        'payer_email' => 'ana@example.test',
    ]);

    $end = now()->addDay();
    $subscription->update(['current_period_end' => $end]);

    Artisan::call('billing:charge-renewals', ['--days-before' => 3]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.dlocalgo.com/v1/payments/recurring/tok_abc'
        && $request->data()['amount'] === 499.0
        && $request->data()['orderId'] === "pingly-sub-{$subscription->id}-".$end->toDateString());

    $renewal = $subscription->invoices()->latest('id')->first();

    expect($renewal->status)->toBe(InvoiceStatus::Paid)
        ->and($renewal->gateway)->toBe('dlocalgo')
        ->and($subscription->fresh()->current_period_end->greaterThan($end))->toBeTrue();

    // A second pass over the same cycle charges nothing.
    Artisan::call('billing:charge-renewals', ['--days-before' => 3]);
    Http::assertSentCount(3); // open, confirm, one renewal
});

it('sends the customer to 3-D Secure and activates on the notification', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes(['status' => 'PENDING', 'redirect_url' => 'https://3ds.example.test/challenge']);

    $tenant = directWorkspace('MX');
    $plan = directPlan();
    $billing = app(BillingService::class);
    $opened = $billing->openCardCheckout($tenant, $plan);
    $subscription = $billing->subscribe($tenant, $plan, PaymentMethod::Card, [
        'card_token' => 'card_tok_1',
        'checkout_token' => $opened['checkout_token'],
        'payer_email' => 'ana@example.test',
    ]);

    $invoice = $subscription->invoices()->first();

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->payment_instrument_id)->toBe('dlgo_rec:tok_abc')
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and((new \App\Http\Resources\Billing\InvoiceResource($invoice))->resolve()['authentication'])
        ->toBe(['url' => 'https://3ds.example.test/challenge']);

    $body = json_encode(['payment_id' => 'DP-7']);
    $this->call('POST', '/webhook/billing/dlocalgo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => goSignature($body),
    ], $body)->assertOk();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('keeps no card when the first charge is refused', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes(['status' => 'REJECTED']);

    $tenant = directWorkspace('MX');
    $plan = directPlan();
    $billing = app(BillingService::class);
    $opened = $billing->openCardCheckout($tenant, $plan);
    $subscription = $billing->subscribe($tenant, $plan, PaymentMethod::Card, [
        'card_token' => 'card_tok_1',
        'checkout_token' => $opened['checkout_token'],
        'payer_email' => 'ana@example.test',
    ]);

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->payment_instrument_id)->toBeNull()
        ->and($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Failed);
});

it('refuses a checkout token that was never opened, already used, or opened by another workspace', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes();

    $tenant = directWorkspace('MX');
    $other = directWorkspace('MX');
    $plan = directPlan();
    $billing = app(BillingService::class);
    $opened = $billing->openCardCheckout($other, $plan);

    $attempt = fn (Tenant $who, ?string $token) => $billing->subscribe($who, $plan, PaymentMethod::Card, [
        'card_token' => 'card_tok_1',
        'checkout_token' => $token,
        'payer_email' => 'ana@example.test',
    ]);

    expect(fn () => $attempt($tenant, 'made_up'))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => $attempt($tenant, $opened['checkout_token']))->toThrow(\Illuminate\Validation\ValidationException::class);

    // Used once by its own workspace, then gone.
    $attempt($other, $opened['checkout_token']);
    expect(fn () => $attempt($other, $opened['checkout_token']))->toThrow(\Illuminate\Validation\ValidationException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'confirm') && $request->data()['cardToken'] !== 'card_tok_1');
    expect(Subscription::where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('serves the card-checkout endpoint to the workspace', function () {
    Setting::set(DirectBillingConfig::DLOCALGO_SMARTFIELDS_KEY, 'sf_key');
    goCardFakes();

    $tenant = directWorkspace('MX');
    $plan = directPlan();
    $user = $tenant->user;
    \Spatie\Permission\Models\Permission::findOrCreate('billing.manage', 'web');
    $user->givePermissionTo('billing.manage');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/billing/card-checkout', ['plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.checkout_token', 'tok_abc');
});

// --- Brazil: saved cards (Mercado Pago customers & cards) -------------------

function mpCardFakes(array $overrides = []): void
{
    Http::fake(array_merge([
        'api.mercadopago.com/v1/customers/search*' => Http::response(['results' => []]),
        'api.mercadopago.com/v1/customers/cus_1/cards/*' => Http::response([], 200),
        'api.mercadopago.com/v1/customers/cus_1/cards' => Http::response([
            'id' => 'card_9',
            'first_six_digits' => '503143',
            'last_four_digits' => '6351',
            'expiration_month' => 11,
            'expiration_year' => now()->addYears(3)->year,
            'payment_method' => ['id' => 'master', 'payment_type_id' => 'credit_card'],
            'issuer' => ['id' => 24],
            'cardholder' => ['name' => 'ANA DUARTE'],
        ]),
        'api.mercadopago.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.mercadopago.com/preapproval' => Http::response(['id' => 'pre_7', 'status' => 'authorized']),
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 555,
            'status' => 'approved',
            'external_reference' => 'x',
        ]),
    ], $overrides));
}

function brOwnerOf(Tenant $tenant): User
{
    $user = $tenant->user;
    \Spatie\Permission\Models\Permission::findOrCreate('billing.manage', 'web');
    \Spatie\Permission\Models\Permission::findOrCreate('billing.view', 'web');
    $user->givePermissionTo(['billing.manage', 'billing.view']);

    return $user;
}

function brSavedCard(Tenant $tenant, array $attributes = []): \App\Models\SavedCard
{
    return \App\Models\SavedCard::create(array_merge([
        'tenant_id' => $tenant->id,
        'gateway' => 'mercadopago',
        'customer_id' => 'cus_1',
        'customer_email' => 'owner@example.test',
        'card_id' => 'card_9',
        'brand' => 'master',
        'issuer_id' => '24',
        'last_four' => '6351',
        'exp_month' => 11,
        'exp_year' => now()->addYears(3)->year,
        'last_used_at' => now()->subMonth(),
    ], $attributes));
}

it('tells the Brazilian checkout to draw its own card fields', function () {
    expect(app(BillingService::class)->cardSession(directWorkspace('BR')))
        ->toMatchArray(['sdk' => 'mercadopago', 'provider' => 'mercadopago', 'saved_cards' => true]);
});

it('keeps a card at a Mercado Pago customer found or created by email', function () {
    mpCardFakes();

    $tenant = directWorkspace('BR');
    $user = brOwnerOf($tenant);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/billing/cards', ['card_token' => 'tok_new'])
        ->assertCreated()
        ->assertJsonPath('data.last_four', '6351')
        ->assertJsonPath('data.brand', 'master')
        ->assertJsonPath('data.card_id', 'card_9');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/customers'
        && $request->data()['email'] === strtolower($user->email)
        && $request->data()['identification'] === ['type' => 'CPF', 'number' => '12345678909']);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/customers/cus_1/cards'
        && $request->data()['token'] === 'tok_new');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/billing/cards')
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.customer_id');
});

it('subscribes on a saved card as a preapproval that renews itself, in the card owner\'s name', function () {
    mpCardFakes();

    $tenant = directWorkspace('BR');
    $card = brSavedCard($tenant, ['last_used_at' => null]);

    $subscription = app(BillingService::class)->subscribe($tenant, directPlan(), PaymentMethod::Card, [
        'card_token' => 'tok_from_card_and_cvv',
        'saved_card' => $card,
        'payer_email' => 'someone-else@example.test',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/preapproval'
        && $request->data()['card_token_id'] === 'tok_from_card_and_cvv'
        && $request->data()['payer_email'] === 'owner@example.test');

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->payment_instrument_id)->toBe('mp_preapproval:pre_7')
        ->and(app(\App\Services\Billing\Gateways\BillingGateways::class)->forSubscription($subscription)
            ->renewsItself($subscription->payment_instrument_id))->toBeTrue()
        ->and($card->fresh()->last_used_at)->not->toBeNull();
});

it('takes a card added for a refused first charge back out of the list', function () {
    mpCardFakes(['api.mercadopago.com/preapproval' => Http::response(['message' => 'cc_rejected_other_reason'], 400)]);

    $tenant = directWorkspace('BR');
    $card = brSavedCard($tenant, ['last_used_at' => null]);

    $thrown = null;
    try {
        app(BillingService::class)->subscribe($tenant, directPlan(), PaymentMethod::Card, [
            'card_token' => 'tok_1',
            'saved_card' => $card,
            'discard_card_on_failure' => true,
            'payer_email' => 'owner@example.test',
        ]);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();

    expect(\App\Models\SavedCard::find($card->id))->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.mercadopago.com/v1/customers/cus_1/cards/card_9');
});

it('never discards a card that has already paid for something', function () {
    mpCardFakes(['api.mercadopago.com/preapproval' => Http::response(['message' => 'cc_rejected_other_reason'], 400)]);

    $tenant = directWorkspace('BR');
    $card = brSavedCard($tenant);

    try {
        app(BillingService::class)->subscribe($tenant, directPlan(), PaymentMethod::Card, [
            'card_token' => 'tok_1',
            'saved_card' => $card,
            'discard_card_on_failure' => true,
            'payer_email' => 'owner@example.test',
        ]);
    } catch (\Throwable) {
    }

    expect(\App\Models\SavedCard::find($card->id))->not->toBeNull();
});

it('tops up the balance with a saved card and its CVV, charged once', function () {
    mpCardFakes();

    $tenant = directWorkspace('BR');
    $user = brOwnerOf($tenant);
    $card = brSavedCard($tenant);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/credits/topup', [
            'amount_cents' => 5000,
            'method' => 'card',
            'saved_card_id' => $card->id,
            'card_token' => 'tok_cvv',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment_method', 'card');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/payments'
        && $request->data()['token'] === 'tok_cvv'
        && $request->data()['transaction_amount'] === 50.0
        && $request->data()['payment_method_id'] === 'master'
        && $request->data()['issuer_id'] === 24
        && $request->data()['payer'] === ['type' => 'customer', 'id' => 'cus_1']
        && str_starts_with($request->data()['external_reference'], 'pingly-topup-'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'preapproval'));

    expect(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(5000);
});

it('fails a refused top-up without crediting anything', function () {
    mpCardFakes(['api.mercadopago.com/v1/payments' => Http::response(['id' => 556, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount'])]);

    $tenant = directWorkspace('BR');
    $invoice = app(BillingService::class)->createCreditTopupCardPayment($tenant, 5000, brSavedCard($tenant), 'tok_cvv');

    expect($invoice->status)->toBe(InvoiceStatus::Failed)
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(0);
});

it('refuses a saved card that belongs to another workspace', function () {
    mpCardFakes();

    $tenant = directWorkspace('BR');
    $card = brSavedCard(directWorkspace('BR'));

    $this->actingAs(brOwnerOf($tenant), 'sanctum')
        ->postJson('/api/credits/topup', [
            'amount_cents' => 5000,
            'method' => 'card',
            'saved_card_id' => $card->id,
            'card_token' => 'tok_cvv',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('saved_card_id');

    $this->actingAs(brOwnerOf($tenant), 'sanctum')
        ->deleteJson("/api/billing/cards/{$card->id}")
        ->assertNotFound();

    Http::assertNothingSent();
});

it('removes a saved card here and at Mercado Pago', function () {
    mpCardFakes();

    $tenant = directWorkspace('BR');
    $card = brSavedCard($tenant);

    $this->actingAs(brOwnerOf($tenant), 'sanctum')
        ->deleteJson("/api/billing/cards/{$card->id}")
        ->assertOk();

    expect(\App\Models\SavedCard::find($card->id))->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v1/customers/cus_1/cards/card_9'));
});
