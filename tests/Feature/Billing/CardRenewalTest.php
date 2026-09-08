<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
});

function cardSubscription(array $overrides = []): Subscription
{
    $user = User::factory()->create(['email' => 'card-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
    ]);

    $subscription = Subscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Card,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 9990,
        'payment_instrument_id' => 'inst_42',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
    ], $overrides));

    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription->fresh();
}

function renewalResponse(array $overrides = []): array
{
    return ['data' => array_merge([
        'id' => 'pay_renew_1',
        'order_reference' => 'ref',
        'status' => 'paid',
        'instructions' => ['type' => 'card', 'brand' => 'visa', 'last_four' => '4242'],
    ], $overrides)];
}

test('a renewal charges the stored instrument with nobody at the screen', function () {
    Http::fake(['*/payments' => Http::response(renewalResponse())]);
    $subscription = cardSubscription();
    $previousEnd = $subscription->current_period_end;

    $invoice = app(BillingService::class)->chargeRenewal($subscription);

    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    expect($subscription->fresh()->current_period_end->gt($previousEnd))->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        // No security code, no 3-D Secure: there is nobody to type either, and
        // the provider must be one that can take such a charge at all.
        return $body['instrument_id'] === 'inst_42'
            && $body['initiator'] === 'merchant'
            && ! isset($body['security_code']);
    });
});

test('a stored instrument carries its own gateway, so no provider is named', function () {
    // A card token belongs to one gateway and is meaningless to another; the
    // service ignores `provider` beside `instrument_id` outright, and sending
    // the operator's preference here would only invite confusion.
    Setting::set(PaymentServiceConfig::KEY_PROVIDER, 'openpix');
    Http::fake(['*/payments' => Http::response(renewalResponse())]);

    app(BillingService::class)->chargeRenewal(cardSubscription());

    Http::assertSent(fn ($request) => ! isset($request->data()['provider']));
});

test('a cycle already invoiced is never charged a second time', function () {
    Http::fake(['*/payments' => Http::response(renewalResponse())]);
    $subscription = cardSubscription();
    $billing = app(BillingService::class);

    $first = $billing->chargeRenewal($subscription);

    // Same period, asked again — a double-firing scheduler, a second worker, an
    // operator running the command by hand.
    $subscription->update(['current_period_end' => $first->period_start]);
    $again = $billing->chargeRenewal($subscription->fresh());

    expect($again)->toBeNull();
    expect(Invoice::where('order_reference', $first->order_reference)->count())->toBe(1);
});

test('a permanent decline drops the instrument so we stop retrying it', function () {
    // Lost, stolen, closed. It will refuse again tomorrow, and repeatedly
    // retrying a refused card raises the failure ratio the card networks judge
    // every other transaction by.
    Http::fake(['*/payments' => Http::response(renewalResponse([
        'status' => 'declined',
        'decline' => ['category' => 'permanent', 'may_retry' => false, 'code' => '54'],
    ]))]);
    $subscription = cardSubscription();

    app(BillingService::class)->chargeRenewal($subscription);

    expect($subscription->fresh()->payment_instrument_id)->toBeNull();
});

test('a temporary decline keeps the instrument for the next attempt', function () {
    // No funds today, funds on Friday. The order reference carries the period,
    // so trying again cannot double-charge.
    Http::fake(['*/payments' => Http::response(renewalResponse([
        'status' => 'declined',
        'decline' => ['category' => 'temporary', 'may_retry' => true, 'code' => '51'],
    ]))]);
    $subscription = cardSubscription();

    $invoice = app(BillingService::class)->chargeRenewal($subscription);

    expect($invoice->status)->toBe(InvoiceStatus::Failed);
    expect($subscription->fresh()->payment_instrument_id)->toBe('inst_42');
});

test('an unknown outcome leaves the invoice pending rather than failing it', function () {
    Http::fake(['*/payments' => Http::response(renewalResponse(['status' => 'unknown']))]);
    $subscription = cardSubscription();

    $invoice = app(BillingService::class)->chargeRenewal($subscription);

    expect($invoice->status)->toBe(InvoiceStatus::Pending);
    expect($subscription->fresh()->payment_instrument_id)->toBe('inst_42');
});

test('a subscription with no stored instrument is not charged at all', function () {
    Http::fake(['*/payments' => Http::response(renewalResponse())]);
    $subscription = cardSubscription(['payment_instrument_id' => null]);

    expect(app(BillingService::class)->chargeRenewal($subscription))->toBeNull();
    Http::assertNothingSent();
});

test('a transport failure does not stop the scheduler walking the rest', function () {
    // The caller is a command iterating subscriptions; one dead card must not
    // end the run for everybody behind it.
    Http::fake(['*/payments' => Http::response(['code' => 'no_usable_provider', 'message' => 'down'], 503)]);
    $subscription = cardSubscription();

    $invoice = app(BillingService::class)->chargeRenewal($subscription);

    expect($invoice->status)->toBe(InvoiceStatus::Failed);
});

test('the command skips cancelled and instrument-less subscriptions', function () {
    Http::fake(['*/payments' => Http::response(renewalResponse())]);

    cardSubscription(['cancel_at_period_end' => true]);
    cardSubscription(['payment_instrument_id' => null]);
    $due = cardSubscription();

    $this->artisan('billing:charge-renewals', ['--days-before' => 3])->assertSuccessful();

    expect(Invoice::count())->toBe(1);
    expect(Invoice::first()->subscription_id)->toBe($due->id);
});
