<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\SavedCard;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/*
 * A card without automatic renewal (Brazil). The checkout's tick box decides
 * the whole shape of the subscription: ticked, the card is kept and charged by
 * itself (Stripe); unticked, this cycle is one Mercado Pago payment on a saved
 * card with its security code, and every later cycle is an invoice the
 * customer pays the same way. Never a preapproval, never a charge with nobody
 * at a screen.
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

function perCycleWorkspace(): Tenant
{
    $user = User::factory()->create(['email' => 'percycle-'.uniqid().'@example.test']);

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

function perCyclePlan(int $priceCents = 9990): Plan
{
    return Plan::create([
        'name' => 'Pro',
        'slug' => 'percycle-'.uniqid(),
        'price_cents' => $priceCents,
        'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly,
        'is_active' => true,
        'is_public' => true,
    ])->fresh();
}

function perCycleCard(Tenant $tenant): SavedCard
{
    return SavedCard::create([
        'tenant_id' => $tenant->id,
        'gateway' => 'mercadopago',
        'customer_id' => 'cus_1',
        'customer_email' => 'owner@example.test',
        'card_id' => 'card_9',
        'brand' => 'master',
        'issuer_id' => '24',
        'last_four' => '6351',
        'exp_month' => 11,
        'exp_year' => now()->addYears(3)->year,
        'last_used_at' => now()->subMonth(),
    ]);
}

/** Mercado Pago payments, one id per charge, answered from the list given. */
function perCycleFakes(array ...$payments): void
{
    $sequence = Http::sequence();

    foreach ($payments ?: [['status' => 'approved']] as $index => $payment) {
        $sequence->push(['id' => 700 + $index, 'external_reference' => 'x'] + $payment);
    }

    Http::fake(['api.mercadopago.com/v1/payments' => $sequence]);
}

function perCycleSubscribe(Tenant $tenant, SavedCard $card, ?Plan $plan = null): Subscription
{
    return app(BillingService::class)->subscribe($tenant, $plan ?? perCyclePlan(), PaymentMethod::Card, [
        'card_token' => 'tok_cvv',
        'saved_card' => $card,
        'auto_renew' => false,
        'payer_email' => 'ana@example.test',
    ]);
}

/** A subscription whose next cycle has just been invoiced by the scheduler. */
function perCycleRenewal(Tenant $tenant, SavedCard $card): Invoice
{
    $subscription = perCycleSubscribe($tenant, $card);
    $subscription->update(['current_period_end' => now()->addDay()]);

    Artisan::call('billing:pix-generate', ['--days-before' => 3]);

    return $subscription->invoices()->latest('id')->first();
}

// --- The tick box ------------------------------------------------------------

it('offers the choice without ever naming who charges which', function () {
    $billing = app(BillingService::class);
    $tenant = perCycleWorkspace();

    expect($billing->cardSession($tenant))->toMatchArray(['sdk' => 'stripe', 'auto_renew' => true, 'per_cycle_available' => true])
        ->and($billing->cardSession($tenant, autoRenew: false))->toMatchArray(['sdk' => 'mercadopago', 'saved_cards' => true, 'auto_renew' => false]);
});

it('does not offer it where the gateway keeps no cards', function () {
    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, null);

    $billing = app(BillingService::class);
    $tenant = perCycleWorkspace();

    expect($billing->cardSession($tenant)['per_cycle_available'])->toBeFalse()
        ->and(fn () => $billing->cardSession($tenant, autoRenew: false))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// --- The first cycle ---------------------------------------------------------

it('charges the first cycle once at Mercado Pago, as a payment and never a preapproval', function () {
    perCycleFakes();

    $tenant = perCycleWorkspace();
    $subscription = perCycleSubscribe($tenant, perCycleCard($tenant));
    $invoice = $subscription->invoices()->first();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadopago.com/v1/payments'
        && $request->data()['token'] === 'tok_cvv'
        && $request->data()['transaction_amount'] === 99.9
        && $request->data()['payer'] === ['type' => 'customer', 'id' => 'cus_1']
        && $request->data()['external_reference'] === $invoice->order_reference);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'preapproval') || str_contains($request->url(), 'stripe.com'));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->gateway)->toBe('mercadopago')
        ->and($subscription->payment_instrument_id)->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('needs a saved card, because a one-off charge is made on one', function () {
    $tenant = perCycleWorkspace();

    expect(fn () => app(BillingService::class)->subscribe($tenant, perCyclePlan(), PaymentMethod::Card, [
        'card_token' => 'tok_cvv',
        'auto_renew' => false,
        'payer_email' => 'ana@example.test',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    Http::assertNothingSent();
});

it('leaves the running plan alone when the first charge is refused', function () {
    perCycleFakes(['status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']);

    $tenant = perCycleWorkspace();
    $comp = app(BillingService::class)->grantManual($tenant, perCyclePlan(), null, \App\Models\Admin::factory()->create(), 'comp');
    $subscription = perCycleSubscribe($tenant, perCycleCard($tenant));

    expect($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Failed)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($tenant->fresh()->current_subscription_id)->toBe($comp->id);
});

// --- Every cycle after -------------------------------------------------------

it('raises an invoice for the next cycle and charges nothing by itself', function () {
    perCycleFakes();

    $tenant = perCycleWorkspace();
    $renewal = perCycleRenewal($tenant, perCycleCard($tenant));

    Artisan::call('billing:charge-renewals', ['--days-before' => 3]);
    Artisan::call('billing:pix-generate', ['--days-before' => 3]);

    expect($renewal->status)->toBe(InvoiceStatus::Pending)
        ->and($renewal->payment_method)->toBe(PaymentMethod::Card)
        ->and($renewal->payment_id)->toBeNull()
        ->and(Invoice::where('subscription_id', $renewal->subscription_id)->count())->toBe(2)
        ->and(app(BillingService::class)->renewalDue($tenant->fresh())?->id)->toBe($renewal->id);

    Http::assertSentCount(1); // only the first cycle was ever charged
});

it('pays that invoice with a saved card and its security code, and extends the plan', function () {
    perCycleFakes(['status' => 'approved'], ['status' => 'approved']);

    $tenant = perCycleWorkspace();
    $card = perCycleCard($tenant);
    $renewal = perCycleRenewal($tenant, $card);
    $end = $renewal->subscription->current_period_end;

    $this->actingAs($tenant->user, 'sanctum')
        ->getJson("/api/billing/invoices/{$renewal->id}/status")
        ->assertJsonPath('data.card_payable', true);

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson("/api/billing/invoices/{$renewal->id}/pay-card", ['saved_card_id' => $card->id, 'card_token' => 'tok_cvv_2'])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    Http::assertSent(fn ($request) => ($request->data()['token'] ?? null) === 'tok_cvv_2'
        && $request->data()['external_reference'] === $renewal->order_reference);

    expect($renewal->subscription->fresh()->current_period_end->greaterThan($end))->toBeTrue()
        ->and(app(BillingService::class)->renewalDue($tenant->fresh()))->toBeNull();
});

it('keeps the invoice payable after a refused card, so another one can be tried on it', function () {
    perCycleFakes(
        ['status' => 'approved'],
        ['status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount'],
        ['status' => 'approved'],
    );

    $tenant = perCycleWorkspace();
    $card = perCycleCard($tenant);
    $renewal = perCycleRenewal($tenant, $card);
    $pay = fn () => $this->actingAs($tenant->user, 'sanctum')
        ->postJson("/api/billing/invoices/{$renewal->id}/pay-card", ['saved_card_id' => $card->id, 'card_token' => 'tok']);

    $pay()->assertStatus(422)
        ->assertJsonPath('code', 'card_declined')
        // Why, not just that: the page says "no funds", not "check the card".
        ->assertJsonPath('decline_reason', 'insufficient_funds');

    expect($renewal->fresh()->status)->toBe(InvoiceStatus::Pending)
        ->and($renewal->fresh()->payment_id)->toBeNull()
        ->and($renewal->fresh()->meta['last_failure']['decline']['code'])->toBe('cc_rejected_insufficient_amount');

    // The refusal's own notification, arriving late, must not kill the invoice.
    app(BillingService::class)->applyPaymentUpdate(
        ['id' => '701', 'status' => 'declined', 'order_reference' => $renewal->order_reference],
        'mercadopago',
    );

    expect($renewal->fresh()->status)->toBe(InvoiceStatus::Pending);

    $pay()->assertOk()->assertJsonPath('data.status', 'paid');
});

it('refuses a second attempt while the first is still being reviewed', function () {
    Http::fake([
        'api.mercadopago.com/v1/payments/701' => Http::response(['id' => 701, 'status' => 'in_process']),
        'api.mercadopago.com/v1/payments' => Http::sequence()
            ->push(['id' => 700, 'status' => 'approved'])
            ->push(['id' => 701, 'status' => 'in_process']),
    ]);

    $tenant = perCycleWorkspace();
    $card = perCycleCard($tenant);
    $renewal = perCycleRenewal($tenant, $card);
    $pay = fn () => $this->actingAs($tenant->user, 'sanctum')
        ->postJson("/api/billing/invoices/{$renewal->id}/pay-card", ['saved_card_id' => $card->id, 'card_token' => 'tok']);

    $pay()->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.card_payable', false);
    $pay()->assertStatus(409)->assertJsonPath('code', 'payment_in_review');

    Http::assertSentCount(3); // first cycle, one attempt, one read-back — never a second charge
});

it('will not pay by card an invoice that is not a per-cycle card renewal', function () {
    perCycleFakes();

    $tenant = perCycleWorkspace();
    $card = perCycleCard($tenant);
    $paid = perCycleSubscribe($tenant, $card)->invoices()->first();

    $this->actingAs($tenant->user, 'sanctum')
        ->postJson("/api/billing/invoices/{$paid->id}/pay-card", ['saved_card_id' => $card->id, 'card_token' => 'tok'])
        ->assertStatus(422);

    $other = perCycleWorkspace();

    $this->actingAs($other->user, 'sanctum')
        ->postJson("/api/billing/invoices/{$paid->id}/pay-card", ['saved_card_id' => $card->id, 'card_token' => 'tok'])
        ->assertStatus(403);
});
