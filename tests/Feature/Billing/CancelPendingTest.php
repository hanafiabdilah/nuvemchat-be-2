<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\PaymentAlreadySettledException;
use App\Models\Invoice;
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
    Bus::fake();
    Http::preventStrayRequests();
});

function cancelTestTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'ana-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function cancelTestPlan(): Plan
{
    return Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.uniqid(),
        'price_cents' => 9990,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
    ]);
}

/** A tenant sitting on an unpaid pix checkout (the state this feature exists for). */
function pendingPixCheckout(): array
{
    Http::fake(['*/v1/payments' => Http::response([
        'id' => 555,
        'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR', 'qr_code_base64' => 'B64']],
    ])]);

    $tenant = cancelTestTenant();
    $subscription = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    return [$tenant->fresh(), $subscription->fresh()];
}

test('cancelling an unpaid pix checkout frees the tenant to pick another plan', function () {
    [$tenant, $subscription] = pendingPixCheckout();

    Http::fake(['*/v1/payments/555' => Http::response(['id' => 555, 'status' => 'cancelled'])]);

    app(BillingService::class)->cancelPendingCheckout($subscription);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
    // Detached — otherwise the plan grid keeps badging it as the current plan.
    expect($tenant->fresh()->current_subscription_id)->toBeNull();
    expect($tenant->fresh()->currentSubscription)->toBeNull();
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('cancelling kills the pix charge at MercadoPago', function () {
    [, $subscription] = pendingPixCheckout();

    Http::fake(['*/v1/payments/555' => Http::response(['id' => 555, 'status' => 'cancelled'])]);

    app(BillingService::class)->cancelPendingCheckout($subscription);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/v1/payments/555')
        && ($request->data()['status'] ?? null) === 'cancelled');

    expect(Invoice::where('subscription_id', $subscription->id)->first()->status)
        ->toBe(InvoiceStatus::Cancelled);
});

test('a pix that settles mid-cancel is honoured, not voided', function () {
    [$tenant, $subscription] = pendingPixCheckout();

    // MercadoPago refuses to cancel a charge it already approved; the re-read is
    // what stops us from voiding an invoice the tenant actually paid.
    Http::fake([
        '*/v1/payments/555' => Http::sequence()
            ->push(['message' => 'cannot cancel an approved payment'], 400)
            ->push(['id' => 555, 'status' => 'approved', 'external_reference' => "tenant:{$tenant->id}:sub:{$subscription->id}"]),
    ]);

    expect(fn () => app(BillingService::class)->cancelPendingCheckout($subscription))
        ->toThrow(PaymentAlreadySettledException::class);

    expect(Invoice::where('subscription_id', $subscription->id)->first()->status)->toBe(InvoiceStatus::Paid);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    expect($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeTrue();
});

test('switching plans voids the charge left open by the old one', function () {
    $pix = fn (int $id) => [
        'id' => $id,
        'status' => 'pending',
        'point_of_interaction' => ['transaction_data' => ['qr_code' => "QR{$id}", 'qr_code_base64' => "B64{$id}"]],
    ];

    // Specific stub first — it must win over the wildcard for the PUT that voids 555.
    Http::fake([
        '*/v1/payments/555' => Http::response(['id' => 555, 'status' => 'cancelled']),
        '*/v1/payments' => Http::sequence()->push($pix(555))->push($pix(777)),
    ]);

    $tenant = cancelTestTenant();
    $old = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    app(BillingService::class)->subscribe($tenant->fresh(), cancelTestPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    // The superseded QR must be dead: paying it would settle a charge on a
    // subscription the tenant is no longer pointed at.
    expect(Invoice::where('subscription_id', $old->id)->first()->status)->toBe(InvoiceStatus::Cancelled);
    expect(Invoice::where('tenant_id', $tenant->id)->where('status', InvoiceStatus::Pending)->count())->toBe(1);
});

test('cancel() tears down an unpaid checkout instead of scheduling a period end', function () {
    [$tenant, $subscription] = pendingPixCheckout();

    Http::fake(['*/v1/payments/555' => Http::response(['id' => 555, 'status' => 'cancelled'])]);

    app(BillingService::class)->cancel($subscription);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
    expect($tenant->fresh()->current_subscription_id)->toBeNull();
});

test('cancel() still schedules a period end for a paid subscription', function () {
    Http::fake(['*/preapproval' => Http::response(['id' => 'PRE-9', 'status' => 'authorized'])]);
    $tenant = cancelTestTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token_id' => 'tok_ok',
    ]);

    Http::fake(['*/preapproval/PRE-9' => Http::response(['id' => 'PRE-9', 'status' => 'cancelled'])]);

    app(BillingService::class)->cancel($subscription);

    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active); // runs to period end
    expect($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
});
