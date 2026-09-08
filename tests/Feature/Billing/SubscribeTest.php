<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\MissingBillingIdentityException;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();          // notifications are queued via the bus
    Http::preventStrayRequests(); // nothing may reach the real payment service
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
});

function billingTenant(bool $withDocument = true): Tenant
{
    $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana-'.uniqid().'@example.test']);
    $tenant = Tenant::create(array_filter([
        'user_id' => $user->id,
        'billing_name' => $withDocument ? 'Ana Duarte' : null,
        'billing_document_type' => $withDocument ? 'CPF' : null,
        'billing_document_number' => $withDocument ? '12345678909' : null,
    ]));
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function billingPlan(): Plan
{
    return Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 9990,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'trial_days' => 14,   // a trial offer must still not grant access before payment
        'is_active' => true,
    ]);
}

function fakePayment(array $overrides = []): array
{
    return ['data' => array_merge([
        'id' => '9001',
        'order_reference' => 'ref',
        'status' => 'pending',
        'instructions' => ['type' => 'pix', 'qr_code' => 'QR', 'qr_code_image' => 'data:image/png;base64,B64'],
    ], $overrides)];
}

// --- What the payment service is actually sent ----------------------------

test('a pix charge is sent in minor units with an explicit expiry', function () {
    Http::fake(['*/payments' => Http::response(fakePayment())]);

    app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        // Minor units, never a decimal: 19.99 is not representable in binary
        // floating point and most JSON clients parse numbers as doubles.
        return $body['amount'] === 9990
            && $body['currency'] === 'BRL'
            && $body['payment_method'] === 'pix'
            // Left out, providers apply their own default — usually 24 hours
            // and rarely what the checkout intended.
            && ! empty($body['expires_at']);
    });
});

test('the order reference carries the period, so a cycle cannot be billed twice', function () {
    Http::fake(['*/payments' => Http::response(fakePayment())]);

    $subscription = app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    $reference = Invoice::where('subscription_id', $subscription->id)->value('order_reference');

    // pingly:sub:{id}:{period start}. A random value per attempt would disable
    // the payment service's uniqueness guarantee entirely, and nothing here
    // would notice until somebody was charged twice for one month.
    expect($reference)->toMatch('/^pingly:sub:\d+:\d{4}-\d{2}-\d{2}$/');

    Http::assertSent(fn ($request) => $request->data()['order_reference'] === $reference);
});

test('every write carries an idempotency key', function () {
    Http::fake(['*/payments' => Http::response(fakePayment())]);

    app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(fn ($request) => ! empty($request->header('Idempotency-Key')));
});

test('the pix instruction lands on the columns the SPA already reads', function () {
    Http::fake(['*/payments' => Http::response(fakePayment())]);

    $subscription = app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    $invoice = Invoice::where('subscription_id', $subscription->id)->latest('id')->first();

    expect($invoice->pix_qr_code)->toBe('QR');
    expect($invoice->pix_copy_paste)->toBe('QR');
    expect($invoice->pix_qr_code_base64)->toBe('data:image/png;base64,B64');
    expect($invoice->payment_id)->toBe('9001');
});

test('a rejected pix charge does not leave a payable invoice with no QR', function () {
    // The invoice row is created before the provider call, so a rejection used to
    // strand it as `pending` forever with no QR — unpayable, unviewable, and enough
    // to make billing:pix-generate's $hasOpen guard skip issuing a real one.
    Http::fake(['*/payments' => Http::response(['code' => 'payment_refused', 'message' => 'amount below minimum'], 422)]);
    $tenant = billingTenant();

    expect(fn () => app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]))->toThrow(Exception::class);

    $invoice = Invoice::where('tenant_id', $tenant->id)->latest('id')->first();
    expect($invoice->status)->toBe(InvoiceStatus::Failed);
    expect(Invoice::where('tenant_id', $tenant->id)->where('status', InvoiceStatus::Pending)->exists())->toBeFalse();
});

// --- The billing identity every charge needs ------------------------------

test('a workspace with no CPF is refused before the service is called', function () {
    // Refused here, where the sentence can still say what to do. The service
    // would reject it by naming `customer.document_number`, which the person
    // reading it has never seen and cannot find in this product.
    $tenant = billingTenant(withDocument: false);

    expect(fn () => app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]))->toThrow(MissingBillingIdentityException::class);

    Http::assertNothingSent();
});

test('the document travels with the charge', function () {
    Http::fake(['*/payments' => Http::response(fakePayment())]);

    app(BillingService::class)->subscribe(billingTenant(), billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(function ($request) {
        $customer = $request->data()['customer'] ?? [];

        return $customer['document_type'] === 'CPF'
            && $customer['document_number'] === '12345678909'
            && $customer['reference'] !== ''; // ours, so we never store theirs
    });
});

// --- No access before payment --------------------------------------------

test('a pix subscriber has no access until the charge is paid', function () {
    Http::fake(['*/payments' => Http::response(fakePayment())]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('a declined card leaves no usable subscription behind', function () {
    // The regression this guards: createPendingSubscription commits a row and
    // points current_subscription_id at it before the provider is called. If
    // that row were usable, a refusal would leave the tenant with unlimited
    // free access — and with no current_period_end it would never lapse.
    Http::fake(['*/payments' => Http::response(fakePayment([
        'status' => 'declined',
        'decline' => ['category' => 'permanent', 'may_retry' => false, 'code' => '51'],
    ]))]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token' => 'tok_bad',
    ]);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
    expect(Invoice::where('subscription_id', $subscription->id)->value('status'))
        ->toBe(InvoiceStatus::Failed);
});

test('a failed provider call leaves no usable subscription behind', function () {
    Http::fake(['*/payments' => Http::response(['code' => 'no_usable_provider', 'message' => 'nothing configured'], 503)]);
    $tenant = billingTenant();

    expect(fn () => app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token' => 'bad_token',
    ]))->toThrow(Exception::class);

    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('a card subscriber gets access in the same response, and the card is kept', function () {
    // A card is synchronous: the outcome arrives in the response that created
    // the payment, unlike a Pix which returns an instruction and then waits.
    Http::fake(['*/payments' => Http::response(fakePayment([
        'status' => 'paid',
        'instructions' => ['type' => 'card', 'brand' => 'visa', 'last_four' => '4242'],
        'instrument' => ['id' => 'inst_77', 'type' => 'card_token'],
        'customer' => ['id' => 'cus_5', 'reference' => 'tenant:1'],
    ]))]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token' => 'tok_ok',
    ]);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeTrue();

    // Without this there is nothing to bill next month: no preapproval exists
    // any more, so the stored instrument is the entire renewal mechanism.
    expect($subscription->fresh()->payment_instrument_id)->toBe('inst_77');

    Http::assertSent(fn ($request) => $request->data()['store_instrument'] === true
        && $request->data()['initiator'] === 'customer');
});

test('an unknown outcome is not treated as a failure', function () {
    // The gateway never answered, so nobody knows whether the charge exists.
    // Marking it failed and letting the customer try again is exactly how
    // somebody gets billed twice.
    Http::fake(['*/payments' => Http::response(fakePayment(['status' => 'unknown']))]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token' => 'tok_1',
    ]);

    expect(Invoice::where('subscription_id', $subscription->id)->value('status'))
        ->toBe(InvoiceStatus::Pending);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('a pending subscription cannot outlive its lapse check', function () {
    // current_period_end must be set for ProcessOverdueSubscriptions to ever see it;
    // a null deadline reads as "no expiry" in Subscription::isUsable().
    Http::fake(['*/payments' => Http::response(fakePayment())]);
    $tenant = billingTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, billingPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    expect($subscription->fresh()->isUsable())->toBeFalse();
});
