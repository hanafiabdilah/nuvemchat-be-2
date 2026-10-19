<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\SavedCard;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\PlanChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
 * Brazil, PAYMENT_METHOD=direct, with Stripe's keys filled in: a card
 * subscription — and only that — is charged at Stripe, on a card we keep and
 * renew ourselves. Pix and top-ups stay at Mercado Pago.
 *
 * What these pin down is the seam between the two companies: the checkout sees
 * one list of methods, a card typed for a plan never reaches Mercado Pago, a
 * Mercado Pago card is never offered to Stripe, and nothing that already
 * exists is moved.
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

function stripeWorkspace(): Tenant
{
    $user = User::factory()->create(['email' => 'stripe-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = 'BR';
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $tenant->forceFill([
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ])->save();

    return $tenant->fresh();
}

function stripePlan(int $priceCents = 9990, string $name = 'Pro'): Plan
{
    return Plan::create([
        'name' => $name,
        'slug' => 'stripe-'.uniqid(),
        'price_cents' => $priceCents,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
        'is_public' => true,
    ])->fresh();
}

function stripeIntent(array $overrides = []): array
{
    return array_merge([
        'id' => 'pi_1',
        'object' => 'payment_intent',
        'status' => 'succeeded',
        'customer' => 'cus_1',
        'payment_method' => 'pm_1',
        'client_secret' => 'pi_1_secret_abc',
        'metadata' => [],
        'latest_charge' => [
            'id' => 'ch_1',
            'refunded' => false,
            'amount_refunded' => 0,
            'disputed' => false,
            'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]],
        ],
    ], $overrides);
}

/** Every PaymentIntent created gets its own id, as it would at Stripe. */
function stripeFakes(array $intent = []): void
{
    $created = 0;

    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => function () use (&$created, $intent) {
            $created++;

            return Http::response(stripeIntent(['id' => "pi_{$created}"] + $intent));
        },
    ]);
}

function stripeSubscribe(Tenant $tenant, ?Plan $plan = null): Subscription
{
    return app(BillingService::class)->subscribe($tenant, $plan ?? stripePlan(), PaymentMethod::Card, [
        'card_token' => 'pm_1',
        'payer_email' => 'ana@example.test',
    ]);
}

function stripeSignature(string $body, string $secret = 'whsec_test', ?int $at = null): string
{
    $at ??= time();

    return "t={$at},v1=".hash_hmac('sha256', "{$at}.{$body}", $secret);
}

// --- Which company takes what ------------------------------------------------

it('sends Brazilian card subscriptions to Stripe and leaves everything else at Mercado Pago', function () {
    $gateways = app(BillingGateways::class);
    $tenant = stripeWorkspace();

    expect($gateways->forCardSubscription($tenant)->name())->toBe('stripe')
        ->and($gateways->forTenant($tenant)->name())->toBe('mercadopago');
});

it('keeps the Mercado Pago preapproval until both Stripe keys are stored', function () {
    Setting::set(DirectBillingConfig::STRIPE_PUBLISHABLE_KEY, null);

    expect(app(BillingGateways::class)->forCardSubscription(stripeWorkspace())->name())->toBe('mercadopago');
});

it('shows the checkout one list: the card from Stripe, Pix from Mercado Pago', function () {
    $billing = app(BillingService::class);
    $tenant = stripeWorkspace();
    $methods = collect($billing->paymentMethodsFor($tenant));

    expect($billing->offeredMethods($tenant))->toBe(['card', 'pix'])
        ->and($methods->where('method', 'card'))->toHaveCount(1)
        ->and($methods->firstWhere('method', 'card')['public_key'])->toBe('pk_test_public')
        ->and($billing->cardSession($tenant))->toMatchArray(['sdk' => 'stripe', 'public_key' => 'pk_test_public', 'provider' => 'stripe']);
});

// --- The first charge --------------------------------------------------------

it('charges the card at Stripe and keeps it for the cycles after this one', function () {
    stripeFakes();

    $tenant = stripeWorkspace();
    $subscription = stripeSubscribe($tenant);
    $invoice = $subscription->invoices()->first();

    Http::assertSent(function ($request) use ($invoice) {
        if ($request->url() !== 'https://api.stripe.com/v1/payment_intents') {
            return false;
        }

        $body = $request->data();

        return $request->hasHeader('Idempotency-Key', $invoice->idempotency_key)
            && (int) $body['amount'] === 9990
            && $body['currency'] === 'brl'
            && $body['customer'] === 'cus_1'
            && $body['payment_method'] === 'pm_1'
            && $body['confirm'] === 'true'
            // Accounts opened in 2026 refuse `payment_method_types` outright.
            && ! array_key_exists('payment_method_types', $body)
            && $body['automatic_payment_methods'] === ['enabled' => 'true', 'allow_redirects' => 'never']
            && $body['setup_future_usage'] === 'off_session'
            && $body['metadata']['order_reference'] === $invoice->order_reference;
    });

    // Nothing about a plan's card ever reaches Mercado Pago.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mercadopago.com'));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->gateway)->toBe('stripe')
        ->and($subscription->payment_instrument_id)->toBe('stripe_pm:cus_1:pm_1')
        ->and($subscription->payment_customer_id)->toBe('cus_1')
        ->and($subscription->payment_card)->toMatchArray(['brand' => 'visa', 'last_four' => '4242'])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->gateway)->toBe('stripe')
        ->and($invoice->fresh()->payment_id)->toBe('pi_1')
        ->and($tenant->fresh()->current_subscription_id)->toBe($subscription->id);
});

it('reuses the Stripe customer the workspace already has', function () {
    stripeFakes();

    $tenant = stripeWorkspace();
    stripeSubscribe($tenant);
    stripeSubscribe($tenant, stripePlan(19990, 'Business'));

    Http::assertSentCount(3); // one customer, two payment intents
});

it('carries an upgrade discount on the first charge, which a preapproval never could', function () {
    stripeFakes();

    $tenant = stripeWorkspace();
    stripeSubscribe($tenant);

    $quote = app(PlanChange::class)->quote($tenant->fresh(), stripePlan(19990, 'Business'), PaymentMethod::Card);

    expect($quote['type'])->toBe(PlanChange::UPGRADE)
        ->and($quote['discount_cents'])->toBeGreaterThan(0)
        ->and($quote['charge_now_cents'])->toBeLessThan(19990);
});

it('reports a refused card as a decline and leaves the running plan alone', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => Http::response(['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
            'payment_intent' => stripeIntent(['status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined']]),
        ]], 402),
    ]);

    $tenant = stripeWorkspace();
    $comp = app(BillingService::class)->grantManual($tenant, stripePlan(), null, \App\Models\Admin::factory()->create(), 'comp');
    $subscription = stripeSubscribe($tenant);
    $invoice = $subscription->invoices()->first();

    expect($invoice->status)->toBe(InvoiceStatus::Failed)
        ->and($invoice->meta['failure']['decline'])->toBe(['category' => 'temporary', 'code' => 'insufficient_funds'])
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->payment_instrument_id)->toBeNull()
        ->and($tenant->fresh()->current_subscription_id)->toBe($comp->id);
});

it('never blames the customer when Stripe refuses the request we built', function () {
    // The real answer a 2026 account gave to `payment_method_types`: a fault
    // in our request, not in anything the customer typed.
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => Http::response(['error' => [
            'type' => 'invalid_request_error',
            'message' => 'The `payment_method_types` parameter is no longer supported.',
        ]], 400),
    ]);

    $tenant = stripeWorkspace();
    $comp = app(BillingService::class)->grantManual($tenant, stripePlan(), null, \App\Models\Admin::factory()->create(), 'comp');

    try {
        stripeSubscribe($tenant);
        $this->fail('The refused request should have thrown.');
    } catch (\App\Exceptions\UpstreamServiceException $e) {
        expect($e->getErrorCode())->toBe('payment_unavailable')
            ->and($e->getMessage())->not->toContain('Revise o valor')
            ->and($e->getMessage())->not->toContain('payment_method_types');
    }

    $invoice = \App\Models\Invoice::where('tenant_id', $tenant->id)->latest('id')->first();

    expect($invoice->status)->toBe(InvoiceStatus::Failed)
        // The Back Office still reads Stripe's own sentence, which names the fix.
        ->and($invoice->meta['failure']['message'])->toContain('payment_method_types')
        ->and($tenant->fresh()->current_subscription_id)->toBe($comp->id);
});

it('never offers a Mercado Pago saved card to Stripe', function () {
    $tenant = stripeWorkspace();
    $card = SavedCard::create([
        'tenant_id' => $tenant->id,
        'gateway' => 'mercadopago',
        'customer_id' => 'mp_cus',
        'customer_email' => 'ana@example.test',
        'card_id' => 'mp_card',
        'brand' => 'visa',
        'last_four' => '1111',
    ]);

    expect(fn () => app(BillingService::class)->subscribe($tenant, stripePlan(), PaymentMethod::Card, [
        'card_token' => 'mp_token',
        'saved_card' => $card,
        'payer_email' => 'ana@example.test',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    Http::assertNothingSent();
});

// --- 3-D Secure --------------------------------------------------------------

it('hands the page a client secret when the issuer wants 3-D Secure, and activates once it is read back paid', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => Http::response(stripeIntent(['status' => 'requires_action', 'latest_charge' => null])),
        'api.stripe.com/v1/payment_intents/pi_1*' => Http::response(stripeIntent()),
    ]);

    $tenant = stripeWorkspace();
    $user = $tenant->user;
    \Spatie\Permission\Models\Permission::findOrCreate('billing.view', 'web');
    $user->givePermissionTo('billing.view');

    $subscription = stripeSubscribe($tenant);
    $invoice = $subscription->invoices()->first();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/billing/invoices/{$invoice->id}/status")
        ->assertOk()
        ->assertJsonPath('data.authentication.client_secret', 'pi_1_secret_abc');

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/billing/invoices/{$invoice->id}/status?refresh=1")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.authentication', null);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()->payment_card['last_four'])->toBe('4242');
});

it('drops the card and the pending purchase when 3-D Secure fails', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => Http::response(stripeIntent(['status' => 'requires_action', 'latest_charge' => null])),
        'api.stripe.com/v1/payment_intents/pi_1*' => Http::response(stripeIntent([
            'status' => 'requires_payment_method',
            'last_payment_error' => ['code' => 'payment_intent_authentication_failure'],
            'latest_charge' => null,
        ])),
    ]);

    $tenant = stripeWorkspace();
    $comp = app(BillingService::class)->grantManual($tenant, stripePlan(), null, \App\Models\Admin::factory()->create(), 'comp');
    $subscription = stripeSubscribe($tenant);

    $this->postJson('/webhook/billing/stripe', [
        'id' => 'evt_1',
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_1']],
    ])->assertOk();

    expect($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Failed)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->payment_instrument_id)->toBeNull()
        ->and($tenant->fresh()->current_subscription_id)->toBe($comp->id);
});

// --- Renewal -----------------------------------------------------------------

it('renews the kept card from our own scheduler, off-session, exactly once per cycle', function () {
    stripeFakes();

    $subscription = stripeSubscribe(stripeWorkspace());
    $end = now()->addDay();
    $subscription->update(['current_period_end' => $end]);

    Artisan::call('billing:charge-renewals', ['--days-before' => 3]);

    $renewal = $subscription->invoices()->latest('id')->first();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/payment_intents'
        && ($request->data()['off_session'] ?? null) === 'true'
        && $request->data()['customer'] === 'cus_1'
        && $request->data()['payment_method'] === 'pm_1'
        && ! isset($request->data()['setup_future_usage'])
        && ! isset($request->data()['payment_method_types'])
        && $request->data()['automatic_payment_methods'] === ['enabled' => 'true', 'allow_redirects' => 'never']
        && $request->data()['metadata']['order_reference'] === "pingly-sub-{$subscription->id}-".$end->toDateString());

    expect($renewal->status)->toBe(InvoiceStatus::Paid)
        ->and($renewal->gateway)->toBe('stripe')
        ->and($subscription->fresh()->current_period_end->greaterThan($end))->toBeTrue();

    Artisan::call('billing:charge-renewals', ['--days-before' => 3]);
    Http::assertSentCount(3); // customer, first charge, one renewal
});

it('forgets a card the issuer says is gone, and keeps one that was only short of funds', function (string $declineCode, bool $kept) {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => Http::sequence()
            ->push(stripeIntent())
            ->push(['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => $declineCode,
                'payment_intent' => stripeIntent(['id' => 'pi_2', 'status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined']]),
            ]], 402),
    ]);

    $subscription = stripeSubscribe(stripeWorkspace());
    $subscription->update(['current_period_end' => now()->addDay()]);

    Artisan::call('billing:charge-renewals', ['--days-before' => 3]);

    expect($subscription->invoices()->latest('id')->first()->status)->toBe(InvoiceStatus::Failed)
        ->and($subscription->fresh()->payment_instrument_id !== null)->toBe($kept);
})->with([
    'lost card' => ['lost_card', false],
    'no funds' => ['insufficient_funds', true],
]);

// --- What already exists stays where it is ------------------------------------

it('leaves a running Mercado Pago preapproval to renew itself', function () {
    $tenant = stripeWorkspace();
    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => stripePlan()->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Card,
        'gateway' => 'mercadopago',
        'billing_cycle' => 'monthly',
        'price_cents' => 9990,
        'currency' => 'BRL',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDay(),
        'payment_instrument_id' => 'mp_preapproval:pre_1',
    ]);

    expect(app(BillingService::class)->chargeRenewal($subscription))->toBeNull();

    Http::assertNothingSent();
});

// --- Webhook -----------------------------------------------------------------

it('refunds the invoice when Stripe reports the charge refunded', function () {
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_intents' => Http::response(stripeIntent()),
        'api.stripe.com/v1/payment_intents/pi_1*' => Http::response(stripeIntent([
            'latest_charge' => ['id' => 'ch_1', 'refunded' => true, 'amount_refunded' => 9990],
        ])),
    ]);
    Setting::set(DirectBillingConfig::STRIPE_WEBHOOK_SECRET, 'whsec_test');

    $subscription = stripeSubscribe(stripeWorkspace());
    $body = json_encode(['id' => 'evt_2', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_1']]]);

    $this->call('POST', '/webhook/billing/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($body),
    ], $body)->assertOk();

    expect($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Refunded);
});

it('refuses a Stripe notification that is wrongly signed or too old', function (callable $signature) {
    stripeFakes();
    Setting::set(DirectBillingConfig::STRIPE_WEBHOOK_SECRET, 'whsec_test');

    stripeSubscribe(stripeWorkspace());
    $sent = count(Http::recorded());
    $body = json_encode(['id' => 'evt_3', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]]);

    $this->call('POST', '/webhook/billing/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature($body),
    ], $body)->assertOk()->assertJsonPath('status', 'invalid-signature');

    // Nothing was read back: the body was not even trusted as a pointer.
    expect(Http::recorded())->toHaveCount($sent);
})->with([
    'wrong secret' => [fn (string $body) => stripeSignature($body, 'whsec_other')],
    'replayed' => [fn (string $body) => stripeSignature($body, 'whsec_test', time() - 3600)],
]);

// --- Back Office -------------------------------------------------------------

it('reports Stripe to the Back Office without ever returning its secret key', function () {
    Http::fake(['api.stripe.com/v1/account' => Http::response([
        'id' => 'acct_1',
        'country' => 'BR',
        'charges_enabled' => true,
        'settings' => ['dashboard' => ['display_name' => 'Pingly']],
    ])]);

    $role = \Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bo.settings.manage', 'web'));
    $admin = \App\Models\Admin::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('data.billing_direct.stripe.publishable_key', 'pk_test_public')
        ->assertJsonPath('data.billing_direct.stripe.secret_key_set', true)
        ->assertJsonPath('data.billing_direct.stripe.active', true)
        ->assertJsonMissingPath('data.billing_direct.stripe.secret_key');

    // A blank secret keeps the stored one; clearing the publishable key is the
    // switch back to Mercado Pago.
    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/settings', ['billing_direct' => ['stripe' => ['publishable_key' => '', 'secret_key' => '']]])
        ->assertOk()
        ->assertJsonPath('data.billing_direct.stripe.active', false);

    expect(DirectBillingConfig::stripeSecretKey())->toBe('sk_test_secret');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/billing-gateways/stripe/test')
        ->assertOk()
        ->assertJsonPath('data.account', 'Pingly')
        ->assertJsonPath('data.charges_enabled', true);
});
