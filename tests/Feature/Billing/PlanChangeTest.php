<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use App\Services\Billing\PlanChange;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * Changing plan with proration: an upgrade is paid now minus what is left of
 * the current plan, and only takes over once that payment settles; a downgrade
 * waits for the end of what is already paid for.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');
    Carbon::setTestNow('2026-10-16 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function pcTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'pc-'.uniqid().'@example.test']);
    $tenant = Tenant::create([
        'user_id' => $user->id,
        'billing_name' => 'Ana Duarte',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678909',
    ]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function pcPlan(string $name, int $cents, BillingCycle $cycle = BillingCycle::Monthly): Plan
{
    return Plan::create([
        'name' => $name,
        'slug' => strtolower($name).'-'.uniqid(),
        'price_cents' => $cents,
        'currency' => 'BRL',
        'billing_cycle' => $cycle,
        'trial_days' => 0,
        'is_active' => true,
        'is_public' => true,
        'quotas' => ['max_connections' => $cents / 1000],
    ]);
}

/**
 * A running, paid plan: the current period is 2026-10-01 → 2026-10-31 (30
 * days) and "now" is the 16th, so exactly half of it is unused at noon on the
 * 16th.
 */
function pcActive(Tenant $tenant, Plan $plan, PaymentMethod $method = PaymentMethod::Pix, array $attributes = []): Subscription
{
    $subscription = Subscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'payment_method' => $method,
        'billing_cycle' => $plan->billing_cycle,
        'price_cents' => $plan->price_cents,
        'currency' => 'BRL',
        'quotas_snapshot' => $plan->quotas,
        'features_snapshot' => $plan->features,
        'current_period_start' => '2026-10-01 12:00:00',
        'current_period_end' => '2026-10-31 12:00:00',
    ], $attributes));

    Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'status' => InvoiceStatus::Paid,
        'payment_method' => $method,
        'amount_cents' => $plan->price_cents,
        'currency' => 'BRL',
        'period_start' => '2026-10-01 12:00:00',
        'period_end' => '2026-10-31 12:00:00',
        'paid_at' => '2026-10-01 12:00:00',
        'order_reference' => "pingly-sub-{$subscription->id}-2026-10-01",
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription->fresh();
}

function pcPix(string $id = '777'): array
{
    return ['data' => [
        'id' => $id,
        'order_reference' => 'ref',
        'status' => 'pending',
        'instructions' => ['type' => 'pix', 'qr_code' => 'QR', 'qr_code_image' => 'IMG'],
    ]];
}

// --- Quote ------------------------------------------------------------------

it('credits the unused half of the current plan against an upgrade', function () {
    $tenant = pcTenant();
    pcActive($tenant, pcPlan('Basic', 10000));
    $pro = pcPlan('Pro', 30000);

    $quote = app(PlanChange::class)->quote($tenant->fresh(), $pro, PaymentMethod::Pix);

    expect($quote['type'])->toBe(PlanChange::UPGRADE)
        ->and($quote['credit_cents'])->toBe(5000)
        ->and($quote['remaining_days'])->toBe(15)
        ->and($quote['discount_cents'])->toBe(5000)
        ->and($quote['balance_credit_cents'])->toBe(0)
        ->and($quote['charge_now_cents'])->toBe(25000)
        ->and($quote['next_renewal_at'])->toStartWith('2026-11-16');
});

it('treats a cheaper plan, or a shorter cycle, as a downgrade at the period end', function () {
    $tenant = pcTenant();
    pcActive($tenant, pcPlan('Pro', 30000));

    $cheaper = app(PlanChange::class)->quote($tenant->fresh(), pcPlan('Basic', 10000));
    $daily = app(PlanChange::class)->quote($tenant->fresh(), pcPlan('Daily', 50000, BillingCycle::Daily));

    expect($cheaper['type'])->toBe(PlanChange::DOWNGRADE)
        ->and($cheaper['charge_now_cents'])->toBe(0)
        ->and($cheaper['effective_at'])->toStartWith('2026-10-31')
        ->and($daily['type'])->toBe(PlanChange::DOWNGRADE);
});

it('is an ordinary first subscription when nothing paid is running', function () {
    $tenant = pcTenant();

    $quote = app(PlanChange::class)->quote($tenant, pcPlan('Pro', 30000), PaymentMethod::Pix);

    expect($quote['type'])->toBe(PlanChange::NEW)
        ->and($quote['charge_now_cents'])->toBe(30000)
        ->and($quote['current'])->toBeNull();
});

it('always charges at least one day, paying the rest of a large credit into the balance', function () {
    $tenant = pcTenant();
    // A renewal charged ahead of time: November is prepaid as well.
    $current = pcActive($tenant, pcPlan('Basic', 29000));
    Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $current->id,
        'status' => InvoiceStatus::Paid,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 29000,
        'currency' => 'BRL',
        'period_start' => '2026-10-31 12:00:00',
        'period_end' => '2026-11-30 12:00:00',
        'paid_at' => now(),
        'order_reference' => "pingly-sub-{$current->id}-2026-10-31",
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $current->update(['current_period_end' => '2026-11-30 12:00:00']);

    $quote = app(PlanChange::class)->quote($tenant->fresh(), pcPlan('Pro', 30000), PaymentMethod::Pix);

    // 14,500 left of October + all 29,000 of November.
    expect($quote['credit_cents'])->toBe(43500)
        // One day of the new plan's first cycle (16 Oct → 16 Nov = 31 days).
        ->and($quote['charge_now_cents'])->toBe(968)
        ->and($quote['discount_cents'])->toBe(29032)
        ->and($quote['balance_credit_cents'])->toBe(14468);
});

// --- Upgrade ----------------------------------------------------------------

it('charges the upgrade minus the credit and keeps the old plan until it is paid', function () {
    Http::fake(['*/payments' => Http::response(pcPix())]);
    $tenant = pcTenant();
    $old = pcActive($tenant, pcPlan('Basic', 10000));
    $pro = pcPlan('Pro', 30000);

    $new = app(BillingService::class)->subscribe($tenant->fresh(), $pro, PaymentMethod::Pix, ['payer_email' => 'ana@example.test']);

    $invoice = Invoice::where('subscription_id', $new->id)->first();
    expect($invoice->amount_cents)->toBe(25000)
        ->and($invoice->proration_credit_cents)->toBe(5000)
        ->and($new->replaces_subscription_id)->toBe($old->id);

    Http::assertSent(fn ($request) => $request->data()['amount'] === 25000);

    // Nothing moved yet: the paid plan still runs the workspace.
    expect($tenant->fresh()->current_subscription_id)->toBe($old->id)
        ->and($old->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(app(SubscriptionGate::class)->usable($tenant->fresh()))->toBeTrue()
        ->and(app(BillingService::class)->pendingChangeFor($tenant->fresh())?->id)->toBe($new->id);

    app(BillingService::class)->applyPaymentUpdate(['id' => '777', 'order_reference' => $invoice->order_reference, 'status' => 'paid']);

    expect($tenant->fresh()->current_subscription_id)->toBe($new->id)
        ->and($new->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($new->fresh()->current_period_end->toDateString())->toBe('2026-11-16')
        ->and($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
});

it('leaves the running plan untouched when an unpaid upgrade is abandoned', function () {
    Http::fake([
        '*/payments/777' => Http::response(pcPix()),
        '*/payments' => Http::response(pcPix()),
    ]);
    $tenant = pcTenant();
    $old = pcActive($tenant, pcPlan('Basic', 10000));
    $user = User::find($tenant->user_id);
    $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('billing.manage', 'web'));

    $new = app(BillingService::class)->subscribe($tenant->fresh(), pcPlan('Pro', 30000), PaymentMethod::Pix, ['payer_email' => 'ana@example.test']);

    $this->actingAs($user, 'sanctum')->postJson('/api/billing/pending/cancel')->assertOk();

    expect($new->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($tenant->fresh()->current_subscription_id)->toBe($old->id)
        ->and($old->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('pays a credit that does not fit as a discount into the balance, once', function () {
    $tenant = pcTenant();
    $current = pcActive($tenant, pcPlan('Basic', 29000));
    Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $current->id,
        'status' => InvoiceStatus::Paid,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 29000,
        'currency' => 'BRL',
        'period_start' => '2026-10-31 12:00:00',
        'period_end' => '2026-11-30 12:00:00',
        'paid_at' => now(),
        'order_reference' => "pingly-sub-{$current->id}-2026-10-31",
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $current->update(['current_period_end' => '2026-11-30 12:00:00']);

    Http::fake(['*/payments' => Http::response(pcPix())]);
    $new = app(BillingService::class)->subscribe($tenant->fresh(), pcPlan('Pro', 30000), PaymentMethod::Pix, ['payer_email' => 'ana@example.test']);
    $invoice = Invoice::where('subscription_id', $new->id)->first();

    $billing = app(BillingService::class);
    $billing->applyPaymentUpdate(['id' => '777', 'order_reference' => $invoice->order_reference, 'status' => 'paid']);
    $billing->applyPaymentUpdate(['id' => '777', 'order_reference' => $invoice->order_reference, 'status' => 'paid']);

    $credits = CreditTransaction::where('tenant_id', $tenant->id)->where('type', 'plan_credit')->get();
    expect($credits)->toHaveCount(1)
        ->and($credits->first()->amount_cents)->toBe(14468);
});

it('refuses to charge a downgrade or the plan the workspace already has', function () {
    $tenant = pcTenant();
    $pro = pcPlan('Pro', 30000);
    pcActive($tenant, $pro);

    expect(fn () => app(BillingService::class)->subscribe($tenant->fresh(), pcPlan('Basic', 10000), PaymentMethod::Pix, ['payer_email' => 'a@example.test']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(BillingService::class)->subscribe($tenant->fresh(), $pro, PaymentMethod::Pix, ['payer_email' => 'a@example.test']))
        ->toThrow(ValidationException::class);
});

// --- Downgrade --------------------------------------------------------------

it('schedules a downgrade for the period end and applies it then', function () {
    $tenant = pcTenant();
    $pro = pcPlan('Pro', 30000);
    $basic = pcPlan('Basic', 10000);
    $current = pcActive($tenant, $pro);

    app(BillingService::class)->schedulePlanChange($tenant->fresh(), $basic);

    $current->refresh();
    expect($current->scheduled_plan_id)->toBe($basic->id)
        ->and($current->scheduled_price_cents)->toBe(10000)
        ->and($current->scheduled_change_at->toDateString())->toBe('2026-10-31')
        ->and($current->plan_id)->toBe($pro->id);

    // Not before the paid period ends.
    expect(app(BillingService::class)->applyDueScheduledChanges())->toBe(0);

    Carbon::setTestNow('2026-10-31 12:30:00');
    $this->artisan('billing:process-overdue')->assertSuccessful();

    $current->refresh();
    expect($current->plan_id)->toBe($basic->id)
        ->and($current->price_cents)->toBe(10000)
        ->and($current->quotas_snapshot)->toBe($basic->quotas)
        ->and($current->scheduled_plan_id)->toBeNull();
});

it('bills the renewal after a scheduled downgrade at the new price', function () {
    Http::fake(['*/payments' => Http::response(['data' => ['id' => 'ren_1', 'status' => 'paid']])]);
    $tenant = pcTenant();
    $current = pcActive($tenant, pcPlan('Pro', 30000), PaymentMethod::Card, ['payment_instrument_id' => 'inst_1']);
    $basic = pcPlan('Basic', 10000, BillingCycle::Monthly);

    app(BillingService::class)->schedulePlanChange($tenant->fresh(), $basic);

    // billing:charge-renewals runs three days ahead of the period end.
    Carbon::setTestNow('2026-10-28 12:00:00');
    $invoice = app(BillingService::class)->chargeRenewal($current->fresh());

    expect($invoice->amount_cents)->toBe(10000)
        ->and($invoice->period_start->toDateString())->toBe('2026-10-31');
    Http::assertSent(fn ($request) => ($request->data()['amount'] ?? null) === 10000);

    // Still on Pro until the 31st — the money for it is spent until then.
    expect($current->fresh()->plan_id)->not->toBe($basic->id);
});

it('keeps the current plan when the scheduled downgrade is cancelled', function () {
    $tenant = pcTenant();
    $pro = pcPlan('Pro', 30000);
    $current = pcActive($tenant, $pro);
    app(BillingService::class)->schedulePlanChange($tenant->fresh(), pcPlan('Basic', 10000));

    app(BillingService::class)->cancelScheduledChange($tenant->fresh());

    Carbon::setTestNow('2026-11-01 12:00:00');
    app(BillingService::class)->applyDueScheduledChanges();

    expect($current->fresh()->plan_id)->toBe($pro->id)
        ->and($current->fresh()->scheduled_plan_id)->toBeNull();
});

it('serves the quote and the schedule over the API', function () {
    $tenant = pcTenant();
    pcActive($tenant, pcPlan('Pro', 30000));
    $basic = pcPlan('Basic', 10000);
    $user = User::find($tenant->user_id);
    foreach (['billing.view', 'billing.manage'] as $permission) {
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
    }

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/billing/plan-change?plan_id={$basic->id}")
        ->assertOk()
        ->assertJsonPath('data.type', 'downgrade');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/billing/plan-change/schedule', ['plan_id' => $basic->id])
        ->assertOk()
        ->assertJsonPath('data.scheduled_change.plan.id', $basic->id);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/billing/plan-change/schedule')
        ->assertOk()
        ->assertJsonPath('data.scheduled_change', null);
});

// --- Mercado Pago preapproval ------------------------------------------------

it('pays an upgrade credit into the balance when the card is a preapproval', function () {
    config(['services.billing.payment_method' => 'direct']);
    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Setting::set(DirectBillingConfig::MP_PUBLIC_KEY, 'APP_USR-public');

    $tenant = pcTenant();
    pcActive($tenant, pcPlan('Basic', 10000));

    $quote = app(PlanChange::class)->quote($tenant->fresh(), pcPlan('Pro', 30000), PaymentMethod::Card);

    // The preapproval charges its fixed amount, so the credit cannot ride on it.
    expect($quote['charge_now_cents'])->toBe(30000)
        ->and($quote['discount_cents'])->toBe(0)
        ->and($quote['balance_credit_cents'])->toBe(5000);
});

it('moves a preapproval to the new amount when a downgrade is scheduled, and refuses a cycle change', function () {
    config(['services.billing.payment_method' => 'direct']);
    Setting::set(DirectBillingConfig::MP_ACCESS_TOKEN, 'APP_USR-token');
    Http::fake(['api.mercadopago.com/preapproval/pre_9' => Http::response(['id' => 'pre_9', 'status' => 'authorized'])]);

    $tenant = pcTenant();
    pcActive($tenant, pcPlan('Pro', 30000), PaymentMethod::Card, [
        'gateway' => 'mercadopago',
        'payment_instrument_id' => 'mp_preapproval:pre_9',
    ]);

    expect(fn () => app(BillingService::class)->schedulePlanChange($tenant->fresh(), pcPlan('Daily', 1000, BillingCycle::Daily)))
        ->toThrow(ValidationException::class);

    app(BillingService::class)->schedulePlanChange($tenant->fresh(), pcPlan('Basic', 10000));

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/preapproval/pre_9')
        && $request->data()['auto_recurring']['transaction_amount'] == 100.0);
});
