<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Exceptions\Billing\PaymentAlreadySettledException;
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
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
});

function cancelTestTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'ana-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
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

function pixPayment(string $id, string $status = 'pending'): array
{
    return ['data' => [
        'id' => $id,
        'status' => $status,
        'order_reference' => 'ref-'.$id,
        'instructions' => ['type' => 'pix', 'qr_code' => "QR{$id}", 'qr_code_image' => "B64{$id}"],
    ]];
}

/** A tenant sitting on an unpaid pix checkout (the state this feature exists for). */
function pendingPixCheckout(): array
{
    Http::fake(['*/payments' => Http::response(pixPayment('555'))]);

    $tenant = cancelTestTenant();
    $subscription = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    return [$tenant->fresh(), $subscription->fresh()];
}

test('cancelling an unpaid pix checkout frees the tenant to pick another plan', function () {
    [$tenant, $subscription] = pendingPixCheckout();

    Http::fake(['*/payments/555' => Http::response(pixPayment('555'))]);

    app(BillingService::class)->cancelPendingCheckout($subscription);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
    // Detached — otherwise the plan grid keeps badging it as the current plan.
    expect($tenant->fresh()->current_subscription_id)->toBeNull();
    expect($tenant->fresh()->currentSubscription)->toBeNull();
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeFalse();
});

test('cancelling closes the charge locally after re-reading it', function () {
    // ⚠️ Local only. The payment service has no way to cancel a pending Pix —
    // `void` releases a card authorisation and refuses everything else — so the
    // QR stays payable until it expires. What this does is stop offering it and
    // stop counting it; if it is paid anyway the webhook still honours it.
    [, $subscription] = pendingPixCheckout();

    Http::fake(['*/payments/555' => Http::response(pixPayment('555'))]);

    app(BillingService::class)->cancelPendingCheckout($subscription);

    // The read is the important half: it is what honours a payment that landed
    // in the seconds before the customer pressed cancel.
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), '/payments/555'));

    expect(Invoice::where('subscription_id', $subscription->id)->first()->status)
        ->toBe(InvoiceStatus::Cancelled);
});

test('a pix that settles mid-cancel is honoured, not voided', function () {
    [$tenant, $subscription] = pendingPixCheckout();

    Http::fake(['*/payments/555' => Http::response(pixPayment('555', 'paid'))]);

    expect(fn () => app(BillingService::class)->cancelPendingCheckout($subscription))
        ->toThrow(PaymentAlreadySettledException::class);

    expect(Invoice::where('subscription_id', $subscription->id)->first()->status)->toBe(InvoiceStatus::Paid);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    expect($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
    expect(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeTrue();
});

test('switching plans closes the charge left open by the old one', function () {
    // Specific stub first — it must win over the wildcard for the re-read of 555.
    Http::fake([
        '*/payments/555' => Http::response(pixPayment('555')),
        '*/payments' => Http::sequence()->push(pixPayment('555'))->push(pixPayment('777')),
    ]);

    $tenant = cancelTestTenant();
    $old = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    app(BillingService::class)->subscribe($tenant->fresh(), cancelTestPlan(), PaymentMethod::Pix, [
        'payer_email' => 'ana@example.test',
    ]);

    // The superseded charge must stop counting: paying it would settle an
    // invoice on a subscription the tenant is no longer pointed at.
    expect(Invoice::where('subscription_id', $old->id)->first()->status)->toBe(InvoiceStatus::Cancelled);
    expect(Invoice::where('tenant_id', $tenant->id)->where('status', InvoiceStatus::Pending)->count())->toBe(1);
});

test('cancel() tears down an unpaid checkout instead of scheduling a period end', function () {
    [$tenant, $subscription] = pendingPixCheckout();

    Http::fake(['*/payments/555' => Http::response(pixPayment('555'))]);

    app(BillingService::class)->cancel($subscription);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
    expect($tenant->fresh()->current_subscription_id)->toBeNull();
});

test('cancel() still schedules a period end for a paid subscription', function () {
    Http::fake(['*/payments' => Http::response(['data' => [
        'id' => 'pay_card_1',
        'status' => 'paid',
        'order_reference' => 'ref',
        'instrument' => ['id' => 'inst_1', 'type' => 'card_token'],
    ]])]);
    $tenant = cancelTestTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token' => 'tok_ok',
    ]);

    app(BillingService::class)->cancel($subscription);

    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active); // runs to period end
    expect($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
});

test('a cancelled subscription is simply never charged again', function () {
    // There is no standing authorisation to revoke any more: a stored card is
    // only charged when billing:charge-renewals asks, and it skips a
    // subscription flagged to end.
    Http::fake(['*/payments' => Http::response(['data' => [
        'id' => 'pay_card_2',
        'status' => 'paid',
        'order_reference' => 'ref',
        'instrument' => ['id' => 'inst_2', 'type' => 'card_token'],
    ]])]);
    $tenant = cancelTestTenant();

    $subscription = app(BillingService::class)->subscribe($tenant, cancelTestPlan(), PaymentMethod::Card, [
        'payer_email' => 'ana@example.test',
        'card_token' => 'tok_ok',
    ]);

    app(BillingService::class)->cancel($subscription);
    $subscription->fresh()->update(['current_period_end' => now()->addHours(6)]);

    $before = Invoice::count();
    test()->artisan('billing:charge-renewals')->assertSuccessful();

    expect(Invoice::count())->toBe($before);
});
