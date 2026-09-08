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
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_WEBHOOK_SECRET, WEBHOOK_SECRET);
});

function paidInvoice(InvoiceStatus $status = InvoiceStatus::Paid): Invoice
{
    $user = User::factory()->create(['email' => 'wh-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
    ]);

    $sub = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);

    return Invoice::create([
        'tenant_id' => $tenant->id, 'subscription_id' => $sub->id,
        'status' => $status, 'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 9990, 'currency' => 'BRL',
        'paid_at' => $status === InvoiceStatus::Paid ? now() : null,
        'payment_id' => 'pay-'.uniqid(),
        'order_reference' => 'pingly:sub:'.$sub->id.':'.now()->toDateString(),
    ]);
}

/** Post a signed event exactly the way the payment service does. */
function postWebhook(array $payload, ?string $secret = WEBHOOK_SECRET, ?int $timestamp = null)
{
    $body = json_encode($payload);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? '');

    return test()->call(
        'POST',
        '/webhook/payments',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $body,
    );
}

function paymentEvent(Invoice $invoice, string $status, array $extra = []): array
{
    return [
        'id' => 'evt_'.substr(hash('sha256', $invoice->payment_id.$status), 0, 32),
        'type' => "payment.{$status}",
        'created_at' => now()->toIso8601String(),
        'data' => array_merge([
            'payment_id' => $invoice->payment_id,
            'order_reference' => $invoice->order_reference,
            'status' => $status,
            'amount' => $invoice->amount_cents,
            'currency' => 'BRL',
        ], $extra),
    ];
}

// --- Signature ------------------------------------------------------------

test('an unsigned body is refused', function () {
    $invoice = paidInvoice(InvoiceStatus::Pending);

    postWebhook(paymentEvent($invoice, 'paid'), secret: 'wrong-secret')->assertOk();

    // Answered 200 so the sender stops retrying, but nothing was applied.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
    expect(WebhookEvent::where('signature_valid', false)->exists())->toBeTrue();
});

test('a signature outside the tolerance is refused', function () {
    // The timestamp is inside the signed string, not merely beside it — without
    // this check a captured request replays for as long as the secret lives.
    $invoice = paidInvoice(InvoiceStatus::Pending);

    postWebhook(paymentEvent($invoice, 'paid'), timestamp: time() - 3600)->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

test('with no secret stored nothing is trusted', function () {
    // Deliberately stricter than the MercadoPago verifier this replaces, which
    // waved everything through when unconfigured. An unsigned body here can
    // activate a subscription nobody paid for.
    Setting::set(PaymentServiceConfig::KEY_WEBHOOK_SECRET, null);
    $invoice = paidInvoice(InvoiceStatus::Pending);

    postWebhook(paymentEvent($invoice, 'paid'), secret: null)->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

// --- Delivery ------------------------------------------------------------

test('a paid event activates the subscription', function () {
    $invoice = paidInvoice(InvoiceStatus::Pending);
    $invoice->subscription->update(['status' => SubscriptionStatus::PastDue]);

    postWebhook(paymentEvent($invoice, 'paid'))->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    expect($invoice->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('the same event delivered twice is applied once', function () {
    // The id is stable across every retry and across an operator pressing
    // resend, which is what makes that button safe.
    $invoice = paidInvoice(InvoiceStatus::Pending);
    $event = paymentEvent($invoice, 'paid');

    postWebhook($event)->assertOk();
    $paidAt = $invoice->fresh()->paid_at;

    postWebhook($event)->assertJson(['status' => 'duplicate']);

    expect($invoice->fresh()->paid_at->timestamp)->toBe($paidAt->timestamp);
});

test('an event matches by order reference before the payment id is known', function () {
    // A webhook can overtake the response that created the payment; at that
    // moment the invoice has a reference and no id.
    $invoice = paidInvoice(InvoiceStatus::Pending);
    $invoice->update(['payment_id' => null]);

    postWebhook([
        'id' => 'evt_race',
        'type' => 'payment.paid',
        'created_at' => now()->toIso8601String(),
        'data' => [
            'payment_id' => 'pay-late',
            'order_reference' => $invoice->order_reference,
            'status' => 'paid',
        ],
    ])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    expect($invoice->fresh()->payment_id)->toBe('pay-late');
});

// --- Status handling ------------------------------------------------------

test('an unknown status changes nothing', function () {
    // The gateway did not answer, so nobody knows whether the charge exists.
    // Treating it as a failure is exactly how a customer is billed twice.
    $invoice = paidInvoice(InvoiceStatus::Pending);

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'unknown',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);
});

test('a refund on a paid invoice is recorded', function () {
    // The regression: the idempotency guard returned for ANY status once paid, so the
    // refund arm was unreachable — and a refund only ever arrives for a paid
    // invoice, which made InvoiceStatus::Refunded impossible to reach at all.
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'refunded',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a dispute on a paid invoice is recorded', function () {
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'disputed',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a repeated paid event on a paid invoice stays idempotent', function () {
    $invoice = paidInvoice();
    $paidAt = $invoice->paid_at;

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'paid',
    ]);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe(InvoiceStatus::Paid);
    expect($fresh->paid_at->timestamp)->toBe($paidAt->timestamp); // not re-stamped
});

test('a late decline cannot un-pay a settled invoice', function () {
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'declined',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a late expiry cannot un-pay a settled invoice', function () {
    $invoice = paidInvoice();

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'expired',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

test('a decline on a pending invoice still marks it failed', function () {
    $invoice = paidInvoice(InvoiceStatus::Pending);

    app(BillingService::class)->applyPaymentUpdate([
        'payment_id' => $invoice->payment_id,
        'status' => 'declined',
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Failed);
});

// --- Instruments ----------------------------------------------------------

test('a dead instrument is detached so renewals stop charging it', function () {
    // Without this, billing:charge-renewals keeps charging a card the issuer
    // has already refused — and repeatedly retrying a refused card raises the
    // failure ratio the networks judge every other transaction by.
    $invoice = paidInvoice();
    $subscription = $invoice->subscription;
    $subscription->update([
        'payment_method' => PaymentMethod::Card,
        'payment_instrument_id' => 'inst_9',
    ]);

    postWebhook([
        'id' => 'evt_instrument_1',
        'type' => 'instrument.invalidated',
        'created_at' => now()->toIso8601String(),
        'data' => [
            'instrument_id' => 'inst_9',
            'customer_reference' => 'tenant:1',
            'type' => 'card_token',
            'status' => 'invalid',
            'reason' => 'The issuer refused it permanently.',
            'needs_replacement' => true,
        ],
    ])->assertOk();

    expect($subscription->fresh()->payment_instrument_id)->toBeNull();
});
