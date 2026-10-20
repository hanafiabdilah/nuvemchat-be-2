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
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The card behind a plan we renew ourselves (Stripe, Brazil): changed from the
 * Billing page, and — when a renewal was refused — the owed cycle paid with the
 * new card right there.
 *
 * What these pin down: the cycle keeps one invoice whoever pays it, the new
 * card replaces the old one only once the bank said yes, a refused new card
 * leaves the working one alone, and the scheduler and the customer never
 * charge the same cycle at once.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['services.billing.payment_method' => 'direct']);

    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Setting::set(DirectBillingConfig::MP_PUBLIC_KEY, 'APP_USR-public');
    Setting::set(DirectBillingConfig::STRIPE_SECRET_KEY, 'sk_test_secret');
    Setting::set(DirectBillingConfig::STRIPE_PUBLISHABLE_KEY, 'pk_test_public');
});

function rcWorkspace(): Tenant
{
    $user = User::factory()->create(['email' => 'rc-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = 'BR';
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    \Spatie\Permission\Models\Permission::findOrCreate('billing.manage', 'web');
    \Spatie\Permission\Models\Permission::findOrCreate('billing.view', 'web');
    $user->givePermissionTo(['billing.manage', 'billing.view']);

    $tenant->forceFill([
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ])->save();

    return $tenant->fresh();
}

/** A Stripe card plan, paid up to tomorrow, renewed by us. */
function rcSubscription(Tenant $tenant, array $overrides = []): Subscription
{
    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'rc-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true, 'is_public' => true,
    ]);

    $subscription = Subscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Card,
        'gateway' => 'stripe',
        'billing_cycle' => BillingCycle::Monthly,
        'price_cents' => 9990,
        'currency' => 'BRL',
        'payment_instrument_id' => 'stripe_pm:cus_1:pm_old',
        'payment_customer_id' => 'cus_1',
        'payment_card' => ['brand' => 'visa', 'last_four' => '1956', 'exp_month' => 12, 'exp_year' => 2029],
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
    ], $overrides));

    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription->fresh();
}

function rcIntent(array $overrides = []): array
{
    return array_merge([
        'id' => 'pi_2',
        'object' => 'payment_intent',
        'status' => 'succeeded',
        'customer' => 'cus_1',
        'payment_method' => 'pm_new',
        'client_secret' => 'pi_2_secret',
        'metadata' => [],
        'latest_charge' => [
            'id' => 'ch_2',
            'refunded' => false,
            'amount_refunded' => 0,
            'disputed' => false,
            'payment_method_details' => ['card' => ['brand' => 'mastercard', 'last4' => '7704', 'exp_month' => 3, 'exp_year' => 2031]],
        ],
    ], $overrides);
}

/** Stripe's answer to a refused card: HTTP 402 with the reason and the intent. */
function rcRefusal(string $declineCode, array $extra = []): array
{
    return ['error' => array_merge([
        'type' => 'card_error',
        'code' => 'card_declined',
        'decline_code' => $declineCode,
        'payment_intent' => rcIntent(['id' => 'pi_1', 'status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined']]),
    ], $extra)];
}

function rcSetup(array $overrides = []): array
{
    return array_merge([
        'id' => 'seti_1',
        'object' => 'setup_intent',
        'status' => 'succeeded',
        'customer' => 'cus_1',
        'client_secret' => 'seti_1_secret',
        'payment_method' => [
            'id' => 'pm_new',
            'card' => ['brand' => 'mastercard', 'last4' => '7704', 'exp_month' => 3, 'exp_year' => 2031],
        ],
    ], $overrides);
}

/** The scheduler's renewal, refused by the issuer. */
function rcRefusedRenewal(Subscription $subscription): Invoice
{
    return app(BillingService::class)->chargeRenewal($subscription);
}

// --- What the Billing page is told --------------------------------------------

it('shows a refused renewal on the Billing page, with why, and badges Billing', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(rcRefusal('insufficient_funds'), 402)]);

    $tenant = rcWorkspace();
    rcRefusedRenewal(rcSubscription($tenant));

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.renewal_card.session.sdk', 'stripe')
        ->assertJsonPath('data.renewal_card.session.public_key', 'pk_test_public')
        ->assertJsonPath('data.renewal_card.payment_due.reason', 'insufficient_funds')
        ->assertJsonPath('data.renewal_card.payment_due.amount_cents', 9990)
        ->assertJsonPath('data.renewal_card.payment_due.overdue', false)
        // No funds today may be funds tomorrow: the page says when we try again.
        ->assertJsonPath('data.renewal_card.payment_due.next_attempt_at', fn ($at) => $at !== null);

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/user')
        ->assertJsonPath('data.billing.card_payment_due', true);
});

it('owes nothing while the renewal has not been refused', function () {
    $tenant = rcWorkspace();
    rcSubscription($tenant);

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/billing/subscription')
        ->assertJsonPath('data.renewal_card.session.sdk', 'stripe')
        ->assertJsonPath('data.renewal_card.payment_due', null);

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/user')
        ->assertJsonPath('data.billing.card_payment_due', false);
});

it('offers no card form for a plan whose card cannot be changed here', function (array $overrides) {
    $tenant = rcWorkspace();
    rcSubscription($tenant, $overrides);

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/billing/subscription')
        ->assertJsonPath('data.renewal_card', null);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertStatus(422);
})->with([
    'paid by hand each cycle' => [['auto_renew' => false, 'gateway' => 'mercadopago', 'payment_instrument_id' => null]],
    'kept by the payment service' => [['gateway' => null, 'payment_instrument_id' => 'inst_42']],
    'a Pix plan' => [['payment_method' => PaymentMethod::Pix, 'payment_instrument_id' => null]],
]);

// --- Paying what a refused renewal left owing -----------------------------------

it('pays the refused cycle with a new card, on the same invoice, and keeps that card', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::sequence()
        ->push(rcRefusal('insufficient_funds'), 402)
        ->push(rcIntent())]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);
    $refused = rcRefusedRenewal($subscription);
    $end = $subscription->current_period_end;

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('data.card.last_four', '7704');

    $invoice = $refused->fresh();
    $subscription->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->meta['renewal_attempts'])->toBe(2)
        ->and($invoice->meta['card_update'])->toBeTrue()
        // One cycle, one invoice — whoever paid it.
        ->and(Invoice::where('subscription_id', $subscription->id)->count())->toBe(1)
        ->and($subscription->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_new')
        ->and($subscription->payment_card['last_four'])->toBe('7704')
        ->and($subscription->current_period_end->greaterThan($end))->toBeTrue();

    // On-session, kept for later, under the customer the plan already has.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_intents'
        && $request->data()['payment_method'] === 'pm_new'
        && $request->data()['customer'] === 'cus_1'
        && $request->data()['setup_future_usage'] === 'off_session'
        && ! isset($request->data()['off_session'])
        && $request->data()['metadata']['order_reference'] === $refused->order_reference.'-r2');
});

it('keeps the working card when the new one is refused, and says why', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::sequence()
        ->push(rcRefusal('insufficient_funds'), 402)
        ->push(rcRefusal('incorrect_cvc'), 402)]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);
    $refused = rcRefusedRenewal($subscription);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'card_declined')
        ->assertJsonPath('decline_reason', 'incorrect_cvc');

    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_old')
        ->and($refused->fresh()->status)->toBe(InvoiceStatus::Failed)
        ->and($refused->fresh()->meta['failure']['stage'])->toBe('card_update');

    // The customer is now dealing with this cycle: the old card is not
    // charged again behind their back.
    $this->travel(21)->hours();
    expect(app(BillingService::class)->chargeRenewal($subscription->fresh()))->toBeNull();
    Http::assertSentCount(2);
});

it('finishes 3-D Secure on the page and keeps the card once the bank confirms', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(rcRefusal('insufficient_funds'), 402)
            ->push(rcIntent(['status' => 'requires_action', 'latest_charge' => null])),
        'api.stripe.com/v1/payment_intents/pi_2*' => Http::response(rcIntent()),
    ]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);
    $refused = rcRefusedRenewal($subscription);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertOk()
        ->assertJsonPath('status', 'requires_action')
        ->assertJsonPath('client_secret', 'pi_2_secret')
        ->assertJsonPath('invoice.id', $refused->id);

    // Waiting on the cardholder is not a new card yet.
    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_old');

    $this->actingAs($tenant->user, 'sanctum')
        ->getJson("/api/billing/invoices/{$refused->id}/status?refresh=1")
        ->assertJsonPath('data.status', 'paid');

    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_new')
        ->and($subscription->fresh()->payment_card['last_four'])->toBe('7704');
});

it('stops an unfinished 3-D Secure charge before trying another card', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_2/cancel' => Http::response(rcIntent(['status' => 'canceled'])),
        'api.stripe.com/v1/payment_intents/pi_2*' => Http::response(rcIntent(['status' => 'requires_action', 'latest_charge' => null])),
        'api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(rcRefusal('insufficient_funds'), 402)
            ->push(rcIntent(['status' => 'requires_action', 'latest_charge' => null]))
            ->push(rcIntent(['id' => 'pi_3', 'payment_method' => 'pm_other'])),
    ]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);
    $refused = rcRefusedRenewal($subscription);
    $use = fn (string $card) => $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => $card]);

    $use('pm_new')->assertJsonPath('status', 'requires_action');
    $use('pm_other')->assertJsonPath('status', 'paid');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_2/cancel');

    expect($refused->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($refused->fresh()->payment_id)->toBe('pi_3')
        ->and(Invoice::where('subscription_id', $subscription->id)->count())->toBe(1);
});

it('refuses while the scheduler is charging the same cycle', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(rcRefusal('insufficient_funds'), 402)]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);
    rcRefusedRenewal($subscription);

    $lock = Cache::lock("billing:renewal:{$subscription->id}", 120);
    $lock->get();

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'renewal_in_progress');

    $lock->release();
    Http::assertSentCount(1);
});

it('pays a plan that has already lapsed, starting a fresh cycle from today', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(rcIntent())]);

    $tenant = rcWorkspace();
    // Lapsed, and the dead card already dropped: nothing left to retry.
    $subscription = rcSubscription($tenant, [
        'status' => SubscriptionStatus::PastDue,
        'current_period_end' => now()->subDay(),
        'grace_ends_at' => now()->addDays(2),
        'payment_instrument_id' => null,
    ]);

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/billing/subscription')
        ->assertJsonPath('data.renewal_card.payment_due.overdue', true)
        ->assertJsonPath('data.renewal_card.payment_due.reason', null);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertJsonPath('status', 'paid');

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_new')
        ->and($subscription->current_period_end->isFuture())->toBeTrue()
        ->and(Invoice::where('subscription_id', $subscription->id)->sole()->status)->toBe(InvoiceStatus::Paid);
});

// --- Changing the card before anything goes wrong -------------------------------

it('keeps a new card without charging it when nothing is owed', function () {
    Http::fake(['api.stripe.com/v1/setup_intents' => Http::response(rcSetup())]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertOk()
        ->assertJsonPath('status', 'saved')
        ->assertJsonPath('data.card.last_four', '7704');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/setup_intents'
        && $request->data()['customer'] === 'cus_1'
        && $request->data()['payment_method'] === 'pm_new'
        && $request->data()['usage'] === 'off_session'
        && $request->data()['confirm'] === 'true'
        && $request->data()['automatic_payment_methods'] === ['enabled' => 'true', 'allow_redirects' => 'never']);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payment_intents'));

    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_new')
        ->and(Invoice::count())->toBe(0);
});

it('confirms a kept card after 3-D Secure, for this workspace only', function () {
    Http::fake([
        'api.stripe.com/v1/setup_intents/seti_1*' => Http::response(rcSetup()),
        'api.stripe.com/v1/setup_intents' => Http::response(rcSetup(['status' => 'requires_action', 'payment_method' => 'pm_new'])),
    ]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertJsonPath('status', 'requires_action')
        ->assertJsonPath('client_secret', 'seti_1_secret')
        ->assertJsonPath('setup_id', 'seti_1');

    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_old');

    $other = rcWorkspace();
    rcSubscription($other);

    $this->actingAs($other->user, 'sanctum')
        ->postJson('/api/billing/subscription/card/confirm', ['setup_id' => 'seti_1'])
        ->assertStatus(422);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card/confirm', ['setup_id' => 'seti_1'])
        ->assertOk()
        ->assertJsonPath('status', 'saved');

    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_new');
});

it('keeps the old card when the bank refuses to keep the new one', function () {
    Http::fake(['api.stripe.com/v1/setup_intents' => Http::response(['error' => [
        'type' => 'card_error',
        'code' => 'card_declined',
        'decline_code' => 'do_not_honor',
        'setup_intent' => rcSetup(['status' => 'requires_payment_method', 'payment_method' => null]),
    ]], 402)]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson('/api/billing/subscription/card', ['card_token' => 'pm_new'])
        ->assertStatus(422)
        ->assertJsonPath('decline_reason', 'call_issuer');

    expect($subscription->fresh()->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_old');
});

// --- What the issuer said about retrying ---------------------------------------

it('follows the issuer: never retries a "do not try again", and asks the cardholder when the bank wants them', function (array $error, bool $kept, string $reason) {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => array_merge([
        'type' => 'card_error',
        'code' => 'card_declined',
        'payment_intent' => rcIntent(['id' => 'pi_1', 'status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined']]),
    ], $error)], 402)]);

    $tenant = rcWorkspace();
    $subscription = rcSubscription($tenant);
    rcRefusedRenewal($subscription);

    $this->travel(21)->hours();
    app(BillingService::class)->chargeRenewal($subscription->fresh());

    Http::assertSentCount(1);

    expect($subscription->fresh()->payment_instrument_id !== null)->toBe($kept);

    $this->actingAs($tenant->user, 'sanctum')->getJson('/api/billing/subscription')
        ->assertJsonPath('data.renewal_card.payment_due.reason', $reason)
        ->assertJsonPath('data.renewal_card.payment_due.next_attempt_at', null);
})->with([
    'do not try again' => [['decline_code' => 'generic_decline', 'advice_code' => 'do_not_try_again'], false, 'card_declined'],
    'authentication required' => [['code' => 'authentication_required', 'decline_code' => 'authentication_required'], true, 'authentication_required'],
]);
