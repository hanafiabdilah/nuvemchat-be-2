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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * A refused card renewal is tried again — once a day, on the same invoice,
 * and only while the issuer has not said to stop.
 *
 * Before this, the failed invoice kept the cycle's reference and the guard read
 * it as "already billed": one refusal was the only attempt, and the plan lapsed
 * three days later with nobody having tried again.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
});

function retrySubscription(array $overrides = []): Subscription
{
    $user = User::factory()->create(['email' => 'retry-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'retry-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
    ]);

    $subscription = Subscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Card,
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 9990,
        'currency' => 'BRL',
        'payment_instrument_id' => 'inst_42',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDays(2),
    ], $overrides));

    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription->fresh();
}

/** @param  array<string, mixed>  $overrides */
function retryPayment(array $overrides = []): array
{
    return ['data' => array_merge([
        'id' => 'pay_1',
        'order_reference' => 'ref',
        'status' => 'paid',
    ], $overrides)];
}

function retryRefusal(array $decline = ['category' => 'temporary', 'may_retry' => true, 'code' => '51'], string $id = 'pay_1'): array
{
    return retryPayment(['id' => $id, 'status' => 'declined', 'decline' => $decline]);
}

it('tries a refused renewal again the next day, on the same invoice', function () {
    Http::fake(['*/payments' => Http::sequence()
        ->push(retryRefusal())
        ->push(retryPayment(['id' => 'pay_2']))]);

    $subscription = retrySubscription();
    $billing = app(BillingService::class);

    $first = $billing->chargeRenewal($subscription);
    $firstKey = $first->idempotency_key;

    expect($first->status)->toBe(InvoiceStatus::Failed)
        ->and($first->meta['renewal_attempts'])->toBe(1);

    // The scheduler fires every hour; a bank that said "no funds" at nine says
    // it again at ten, and hammering it is what the networks fine.
    $this->travel(1)->hours();
    expect($billing->chargeRenewal($subscription->fresh()))->toBeNull();
    Http::assertSentCount(1);

    $this->travel(20)->hours();
    $second = $billing->chargeRenewal($subscription->fresh());

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(InvoiceStatus::Paid)
        ->and($second->idempotency_key)->not->toBe($firstKey)
        ->and($second->meta['renewal_attempts'])->toBe(2)
        ->and($second->meta['failures'])->toHaveCount(1)
        ->and($second->meta)->not->toHaveKey('failure')
        // One cycle, one invoice — never a second row to be paid twice.
        ->and(Invoice::where('subscription_id', $subscription->id)->count())->toBe(1)
        ->and($subscription->fresh()->current_period_end->greaterThan($first->period_start))->toBeTrue();

    // The gateway is exactly-once on its reference: a retry under the same
    // one would only be handed the refusal back.
    Http::assertSent(fn ($request) => ($request->data()['order_reference'] ?? null) === $first->order_reference.'-r2');
});

it('gives up after four attempts on one cycle', function () {
    Http::fake(['*/payments' => Http::response(retryRefusal())]);

    $subscription = retrySubscription(['current_period_end' => now()->addDays(5)]);
    $billing = app(BillingService::class);

    foreach (range(1, 5) as $day) {
        $billing->chargeRenewal($subscription->fresh());
        $this->travel(21)->hours();
    }

    Http::assertSentCount(4);

    $invoice = Invoice::sole();

    expect($invoice->meta['renewal_attempts'])->toBe(4)
        ->and($invoice->status)->toBe(InvoiceStatus::Failed);
});

it('never tries again when the issuer says not to', function (array $decline) {
    Http::fake(['*/payments' => Http::response(retryRefusal($decline))]);

    $subscription = retrySubscription();
    $billing = app(BillingService::class);

    $billing->chargeRenewal($subscription);
    $this->travel(21)->hours();
    $billing->chargeRenewal($subscription->fresh());

    Http::assertSentCount(1);
})->with([
    'may not retry' => [['category' => 'temporary', 'may_retry' => false, 'code' => '57']],
    'cardholder must act' => [['category' => 'action_required', 'code' => 'authentication_required']],
]);

it('tries a transport failure again too — no issuer refused anything', function () {
    Http::fake(['*/payments' => Http::sequence()
        ->push(['code' => 'no_usable_provider', 'message' => 'down'], 503)
        ->push(retryPayment())]);

    $subscription = retrySubscription();
    $billing = app(BillingService::class);

    $billing->chargeRenewal($subscription);
    $this->travel(21)->hours();

    expect($billing->chargeRenewal($subscription->fresh())->status)->toBe(InvoiceStatus::Paid)
        ->and(Invoice::count())->toBe(1);
});

it('settles the cycle from a notification about a retried attempt, and ignores the late refusal of the first', function () {
    Http::fake(['*/payments' => Http::sequence()
        ->push(retryRefusal())
        ->push(retryPayment(['id' => 'pay_2', 'status' => 'unknown']))]);

    $subscription = retrySubscription();
    $billing = app(BillingService::class);

    $invoice = $billing->chargeRenewal($subscription);
    $this->travel(21)->hours();
    $billing->chargeRenewal($subscription->fresh());

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->fresh()->payment_id)->toBe('pay_2');

    // The first attempt's refusal, delivered again hours later. Applied, it
    // would fail the attempt that is still with the bank.
    $billing->applyPaymentUpdate(['id' => 'pay_1', 'status' => 'declined', 'order_reference' => $invoice->order_reference]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);

    // The retry is known to the gateway by its `-r2` reference.
    $billing->applyPaymentUpdate(['status' => 'paid', 'order_reference' => $invoice->order_reference.'-r2']);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('leaves the cycle alone while someone else is charging it', function () {
    Http::fake(['*/payments' => Http::response(retryPayment())]);

    $subscription = retrySubscription();
    $lock = Cache::lock("billing:renewal:{$subscription->id}", 120);
    $lock->get();

    expect(app(BillingService::class)->chargeRenewal($subscription))->toBeNull();
    Http::assertNothingSent();

    $lock->release();
});

it('does not re-record a refusal it already recorded', function () {
    Http::fake(['*/payments' => Http::response(retryRefusal())]);

    $subscription = retrySubscription();
    $billing = app(BillingService::class);
    $invoice = $billing->chargeRenewal($subscription);

    // The gateway's own notification of the same refusal: writing it again
    // would replace the stage the retry rules read.
    $billing->applyPaymentUpdate(['id' => 'pay_1', 'status' => 'declined', 'order_reference' => $invoice->order_reference]);

    expect($invoice->fresh()->meta['failure']['stage'])->toBe('card_renewal');

    $this->travel(21)->hours();
    $billing->chargeRenewal($subscription->fresh());

    Http::assertSentCount(2);
});
