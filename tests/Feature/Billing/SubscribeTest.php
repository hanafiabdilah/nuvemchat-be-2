<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();          // notifications are queued via the bus
    Http::preventStrayRequests(); // nothing may reach the real MercadoPago
});

function billingTenant(): Tenant
{
    $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana-' . uniqid() . '@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function billingPlan(): Plan
{
    return Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-' . uniqid(),
        'price_cents' => 9990,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'trial_days' => 14,   // a trial offer must still not grant access before payment
        'is_active' => true,
    ]);
}

// --- Pix: date_of_expiration format --------------------------------------

test('the pix charge sends an expiry MercadoPago accepts', function () {
    Http::fake([
        '*/v1/payments' => Http::response([
            'id' => 123,
            'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR', 'qr_code_base64' => 'B64']],
        ]),
    ]);

    app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(function ($request) {
        $sent = $request->data()['date_of_expiration'] ?? '';

        // yyyy-MM-dd'T'HH:mm:ss.SSS±HH:MM — the milliseconds are what MercadoPago
        // rejects the request for when missing.
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $sent);
    });
});

test('the expiry is not the bare ISO-8601 string MercadoPago rejects', function () {
    Http::fake(['*/v1/payments' => Http::response([
        'id' => 1, 'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => []],
    ])]);

    app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(fn ($request) => ! preg_match(
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
        $request->data()['date_of_expiration'] ?? ''
    ));
});

// --- No access before payment --------------------------------------------

test('a pix subscriber has no access until the charge is paid', function () {
    Http::fake(['*/v1/payments' => Http::response([
        'id' => 1, 'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
    ])]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('a card subscriber has no access while authorization is pending', function () {
    Http::fake(['*/preapproval' => Http::response(['id' => 'PRE-1', 'status' => 'pending'])]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token_id' => 'tok_1',
    ]);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
    expect($subscription->fresh()->mp_preapproval_id)->toBe('PRE-1'); // still persisted
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('a failed provider call leaves no usable subscription behind', function () {
    // The regression: createPendingSubscription committed a usable Trialing row and
    // pointed current_subscription_id at it before calling MercadoPago. A throw here
    // left that row in place — and with no current_period_end it never lapsed, so the
    // tenant kept unlimited free access.
    Http::fake(['*/preapproval' => Http::response(['message' => 'card token invalid'], 400)]);
    $tenant = billingTenant();

    expect(fn () => app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token_id' => 'bad_token',
    ]))->toThrow(Exception::class);

    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('a card subscriber gets access once authorized', function () {
    Http::fake(['*/preapproval' => Http::response(['id' => 'PRE-2', 'status' => 'authorized'])]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token_id' => 'tok_ok',
    ]);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeTrue();
});

test('a pending subscription cannot outlive its lapse check', function () {
    // current_period_end must be set for ProcessOverdueSubscriptions to ever see it;
    // a null deadline reads as "no expiry" in Subscription::isUsable().
    Http::fake(['*/preapproval' => Http::response(['id' => 'PRE-3', 'status' => 'pending'])]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token_id' => 'tok_1',
    ]);

    expect($subscription->fresh()->isUsable())->toBeFalse();
});
