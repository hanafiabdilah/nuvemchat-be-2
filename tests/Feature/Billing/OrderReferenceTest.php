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
use App\Services\Billing\PaymentService\PaymentServiceClient;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * The order reference has to survive the strictest gateway behind the service.
 *
 * dLocal Go forwards it as the payment's `order_id` and refuses anything
 * outside `[A-Za-z0-9\-_]`, under its own name for the field (`invoiceId`).
 * The colon-separated shape this replaces therefore failed **every** payment
 * routed there — not an edge case, the whole provider.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
});

function refTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'ref-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Acme LTDA',
        'billing_document_type' => 'CNPJ',
        'billing_document_number' => '12345678000199',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function refPlan(): Plan
{
    return Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
    ]);
}

function refPixResponse(): array
{
    return ['data' => [
        'id' => 'pay_ref_1',
        'status' => 'pending',
        'order_reference' => 'ref',
        'instructions' => ['type' => 'pix', 'qr_code' => 'QR'],
    ]];
}

test('a subscription reference carries only characters every gateway keeps', function () {
    Http::fake(['*/payments' => Http::response(refPixResponse())]);

    app(BillingService::class)->subscribe(refTenant(), refPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    Http::assertSent(fn ($request) => preg_match('/^[A-Za-z0-9\-_]+$/', $request->data()['order_reference']) === 1);
});

test('a top-up reference does too', function () {
    // The other half of the surface, and the one a customer actually reaches
    // first — Billing → Saldo → Add credit never goes through a plan checkout.
    Http::fake(['*/payments' => Http::response(refPixResponse())]);

    $invoice = app(BillingService::class)->createCreditTopupPixInvoice(refTenant(), 5000);

    expect($invoice->order_reference)->toMatch('/^[A-Za-z0-9\-_]+$/');
});

test('a reference no gateway would keep is refused before one is asked', function () {
    // The guard is at the client, not the call site: it is the chokepoint every
    // payment passes through, so the next purpose somebody adds is covered
    // without remembering to cover it.
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');

    expect(fn () => app(PaymentServiceClient::class)->createPayment([
        'order_reference' => 'sub_8123:2026-08',
        'amount' => 1000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
    ], 'idem-1'))->toThrow(\App\Exceptions\UserFacingException::class);

    Http::assertNothingSent();
});

test('the refusal never reads as the customer having done something wrong', function () {
    // dLocal Go's own sentence names `order_id`, `invoiceId` and a regex. The
    // generic payment_refused copy — "revise o valor e o meio de pagamento" —
    // would send somebody to check a card that was never the problem.
    $exception = UpstreamError::exception(
        UpstreamProvider::PaymentService,
        'dLocal Go allows only letters, digits, hyphens and underscores in an order reference, '
        .'and this payment carries "sub_xxx:2026-08". It travels as the payment\'s order_id and is '
        .'rejected there under dLocal Go\'s own name for that field, invoiceId.',
        upstreamCode: 'payment_refused',
        status: 422,
    );

    expect($exception->getErrorCode())->toBe('payment_reference_invalid')
        ->and($exception->getMessage())->not->toContain('dLocal')
        ->and($exception->getMessage())->not->toContain('invoiceId')
        ->and($exception->getMessage())->not->toContain('order')
        // Ours to fix, so it must not ask them to revise anything of theirs.
        ->and($exception->getMessage())->not->toContain('Revise');
});

test('a cycle already billed under the old colon reference is not billed again', function () {
    // The fix must not become the bug it fixes. A renewal in flight when the
    // shape changed has its invoice stored under the colon form; a guard that
    // only knew the new one would issue a second invoice, and the payment
    // service would see a reference it has never had either.
    Http::fake(['*/payments' => Http::response(refPixResponse())]);

    $tenant = refTenant();
    $plan = refPlan();

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Card,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 9990,
        'payment_instrument_id' => 'inst_1',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
    ]);

    // What the scheduler wrote before the rename, for the cycle now coming due.
    $periodStart = $subscription->current_period_end->copy();
    Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'status' => InvoiceStatus::Paid,
        'payment_method' => PaymentMethod::Card,
        'amount_cents' => 9990,
        'currency' => 'BRL',
        'period_start' => $periodStart,
        'order_reference' => "pingly:sub:{$subscription->id}:".$periodStart->toDateString(),
        'paid_at' => now(),
    ]);

    expect(app(BillingService::class)->chargeRenewal($subscription->fresh()))->toBeNull();

    Http::assertNothingSent();
    expect(Invoice::where('subscription_id', $subscription->id)->count())->toBe(1);
});
