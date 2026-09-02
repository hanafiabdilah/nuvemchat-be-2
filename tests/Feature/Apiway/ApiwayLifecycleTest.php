<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Jobs\RenewApiwaySubscription;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Connection;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Proxy\ApiwayConfig;
use App\Services\Credits\CreditService;
use App\Enums\Credit\CreditTransactionType;
use App\Enums\Notification\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

function lifecycleTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'lc-' . uniqid() . '@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function lifecycleSubscription(Tenant $tenant, array $attributes = []): ApiwaySubscription
{
    return ApiwaySubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-' . uniqid(),
        'provider_subscription_id' => random_int(1000, 999999),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'unit_price_cents' => 4990,
        'total_price_cents' => 4990,
        'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Active,
        'expires_at' => now()->addDays(30),
    ], $attributes));
}

function lifecycleInstance(ApiwaySubscription $row, array $attributes = []): ApiwayInstance
{
    return ApiwayInstance::create(array_merge([
        'tenant_id' => $row->tenant_id,
        'apiway_subscription_id' => $row->id,
        'provider_instance_id' => 'uuid-' . uniqid(),
        'name' => 'Instancia',
        'status' => 'conectado',
    ], $attributes));
}

function lifecyclePlanSubscription(Tenant $tenant, SubscriptionStatus $status, array $features = []): Subscription
{
    $plan = Plan::create([
        'name' => 'Chat', 'slug' => 'chat-' . uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'features' => $features,
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => $status, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990,
        'features_snapshot' => $features,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription;
}

// --- Implicit entitlements (mode 1: instances without a plan) --------------

test('a tenant with no plan but a live instance gets implicit whatsapp_api access', function () {
    $tenant = lifecycleTenant();
    lifecycleInstance(lifecycleSubscription($tenant));

    $gate = app(SubscriptionGate::class);

    expect($gate->usable($tenant))->toBeTrue()
        ->and($gate->feature($tenant, 'whatsapp_api'))->toBeTrue()
        ->and($gate->feature($tenant, 'chat'))->toBeFalse()
        ->and($gate->quota($tenant, 'max_connections'))->toBe(1);
});

test('a tenant with neither plan nor instances stays locked out', function () {
    $tenant = lifecycleTenant();
    $gate = app(SubscriptionGate::class);

    expect($gate->usable($tenant))->toBeFalse()
        ->and($gate->feature($tenant, 'whatsapp_api'))->toBeFalse();
});

test('owning live instances implies whatsapp_api even when the plan lacks the flag', function () {
    $tenant = lifecycleTenant();
    lifecyclePlanSubscription($tenant, SubscriptionStatus::Active, ['chat' => true]);
    lifecycleInstance(lifecycleSubscription($tenant));

    $gate = app(SubscriptionGate::class);

    expect($gate->feature($tenant, 'whatsapp_api'))->toBeTrue()
        ->and($gate->feature($tenant, 'chat'))->toBeTrue();
});

test('an expired instance grants nothing', function () {
    $tenant = lifecycleTenant();
    lifecycleInstance(lifecycleSubscription($tenant, [
        'status' => ApiwaySubscriptionStatus::Expired,
    ]));

    expect(app(SubscriptionGate::class)->usable($tenant))->toBeFalse();
});

// --- apiway:renew ----------------------------------------------------------

test('included subscriptions renew free while the plan is usable', function () {
    $tenant = lifecycleTenant();
    lifecyclePlanSubscription($tenant, SubscriptionStatus::Active);
    $row = lifecycleSubscription($tenant, [
        'source' => ApiwaySubscriptionSource::PlanIncluded,
        'expires_at' => now()->addDays(2),
    ]);

    $this->artisan('apiway:renew')->assertSuccessful();

    Bus::assertDispatched(RenewApiwaySubscription::class, function (RenewApiwaySubscription $job) use ($row) {
        return $job->apiwaySubscriptionId === $row->id
            && $job->idempotencyKey === 'pingly-inc-renew-' . $row->id . '-' . $row->expires_at->format('Ymd');
    });
});

test('included subscriptions stop renewing once the plan is suspended', function () {
    $tenant = lifecycleTenant();
    lifecyclePlanSubscription($tenant, SubscriptionStatus::Suspended);
    lifecycleSubscription($tenant, [
        'source' => ApiwaySubscriptionSource::PlanIncluded,
        'expires_at' => now()->addDays(2),
    ]);

    $this->artisan('apiway:renew')->assertSuccessful();

    Bus::assertNotDispatched(RenewApiwaySubscription::class);
});

test('a unit subscription near expiry is charged to the balance at a freshly quoted price', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 59.9, 'total_price' => 59.9,
        ]]),
    ]);

    $tenant = lifecycleTenant();
    CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => 20_000, 'currency' => 'BRL']);
    $row = lifecycleSubscription($tenant, ['expires_at' => now()->addDays(2)]);

    $this->artisan('apiway:renew')->assertSuccessful();
    // A second run inside the same cycle must not charge again: the reference
    // carries the expiry, which has not moved because the renew is still queued.
    $this->artisan('apiway:renew')->assertSuccessful();

    $debits = CreditTransaction::where('tenant_id', $tenant->id)->get();

    expect($debits)->toHaveCount(1)
        ->and($debits->first()->type)->toBe(CreditTransactionType::Renewal)
        // The re-quoted price, not the stale one stored on the row.
        ->and($debits->first()->amount_cents)->toBe(-5990)
        ->and($debits->first()->reference)->toBe("apiway:renew:{$row->id}:" . $row->expires_at->toDateString())
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe(14_010)
        ->and(Invoice::count())->toBe(0);

    Bus::assertDispatched(RenewApiwaySubscription::class);
});

test('a renewal the balance cannot cover warns instead of charging', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 59.9, 'total_price' => 59.9,
        ]]),
    ]);

    $tenant = lifecycleTenant();
    CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => 500, 'currency' => 'BRL']);
    $row = lifecycleSubscription($tenant, ['expires_at' => now()->addDays(2)]);

    $this->artisan('apiway:renew')->assertSuccessful();

    // Nothing taken, nothing renewed — and a reminder stamped so the warning
    // repeats daily rather than once.
    expect(CreditTransaction::count())->toBe(0)
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe(500)
        ->and($row->fresh()->renewal_reminder_sent_at)->not->toBeNull();

    Bus::assertNotDispatched(RenewApiwaySubscription::class);
});

test('an insufficient balance is warned about a week out, before anything is charged', function () {
    $tenant = lifecycleTenant();
    CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => 0, 'currency' => 'BRL']);
    // Outside the three-day charge window, inside the seven-day warning window.
    $row = lifecycleSubscription($tenant, ['expires_at' => now()->addDays(6)]);

    // No quote is faked on purpose: the early warning must price itself from the
    // row it already has rather than calling the partner for every subscription
    // every day. A stray request fails this suite.
    $this->artisan('apiway:renew')->assertSuccessful();

    expect($row->fresh()->renewal_reminder_sent_at)->not->toBeNull()
        ->and(CreditTransaction::count())->toBe(0);
});

test('a unit card subscription with live auto-debit is left to MercadoPago', function () {
    $tenant = lifecycleTenant();
    lifecycleSubscription($tenant, [
        'mp_preapproval_id' => 'PA-1',
        'expires_at' => now()->addDays(2),
    ]);

    $this->artisan('apiway:renew')->assertSuccessful();

    expect(Invoice::count())->toBe(0);
});

// --- renew() ---------------------------------------------------------------

test('renew mirrors the partner response onto the local row', function () {
    $newExpiry = now()->addDays(60);
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions/*/renew' => Http::response(['data' => [
            'subscription' => ['id' => 42, 'expires_at' => $newExpiry->toISOString()],
            'renewal' => ['cycle' => 'mensal', 'unit_price' => 59.9, 'total_price' => 59.9],
        ]]),
    ]);

    $tenant = lifecycleTenant();
    $row = lifecycleSubscription($tenant, ['renewal_reminder_sent_at' => now()]);

    app(ApiwayService::class)->renew($row, 'test-key');

    $row->refresh();
    expect($row->expires_at->toDateString())->toBe($newExpiry->toDateString())
        ->and($row->total_price_cents)->toBe(5990)
        ->and($row->renewal_reminder_sent_at)->toBeNull();

    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'test-key'));
});

test('a replayed renewal payment reuses the same idempotency key', function () {
    // ProxyBR extends expiry additively and treats Idempotency-Key as optional
    // (partner security review, 2026-08-24 — still open on their side). A
    // duplicate MercadoPago webhook must therefore replay, not renew twice:
    // the key is derived from the invoice, never generated per call.
    $tenant = lifecycleTenant();
    $row = lifecycleSubscription($tenant);

    $invoice = Invoice::create([
        'tenant_id' => $tenant->id,
        'apiway_subscription_id' => $row->id,
        'purpose' => InvoicePurpose::ApiwayRenewal,
        'status' => InvoiceStatus::Paid,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 4990,
        'currency' => 'BRL',
        'paid_at' => now(),
    ]);

    $service = app(ApiwayService::class);
    $service->handleApiwayInvoicePaid($invoice);
    $service->handleApiwayInvoicePaid($invoice);

    $keys = collect(Bus::dispatched(RenewApiwaySubscription::class))
        ->map(fn (RenewApiwaySubscription $job) => $job->idempotencyKey)
        ->unique();

    expect($keys)->toHaveCount(1)
        ->and($keys->first())->toBe('pingly-renew-inv-' . $invoice->id);
});

// --- apiway:sync (no grace at ProxyBR) -------------------------------------

test('sync expires overdue rows, releases connections and voids open invoices', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions*' => Http::response(['data' => [], 'meta' => []]),
        'api.mercadopago.com/v1/payments/*' => Http::response(['status' => 'cancelled']),
    ]);

    $tenant = lifecycleTenant();
    $row = lifecycleSubscription($tenant, ['expires_at' => now()->subHour()]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id, 'channel' => Channel::WhatsappApiway,
        'name' => 'API', 'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'uuid-x', 'token' => 'tok'],
    ]);
    $instance = lifecycleInstance($row, ['connection_id' => $connection->id]);

    $openInvoice = Invoice::create([
        'tenant_id' => $tenant->id, 'apiway_subscription_id' => $row->id,
        'purpose' => InvoicePurpose::ApiwayRenewal, 'status' => InvoiceStatus::Pending,
        'payment_method' => PaymentMethod::Pix, 'amount_cents' => 4990, 'currency' => 'BRL',
        'mp_payment_id' => 'MP-OPEN-1',
    ]);

    $this->artisan('apiway:sync')->assertSuccessful();

    expect($row->fresh()->status)->toBe(ApiwaySubscriptionStatus::Expired)
        ->and($instance->fresh()->connection_id)->toBeNull()
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Inactive)
        ->and($openInvoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

// --- cancel() --------------------------------------------------------------

test('cancel revokes at ProxyBR, kills the preapproval and releases the connection', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions/*/cancel' => Http::response(['data' => ['status' => 'cancelled']]),
        'api.mercadopago.com/preapproval/*' => Http::response(['status' => 'cancelled']),
    ]);

    $tenant = lifecycleTenant();
    $row = lifecycleSubscription($tenant, ['mp_preapproval_id' => 'PA-9']);

    $connection = Connection::create([
        'tenant_id' => $tenant->id, 'channel' => Channel::WhatsappApiway,
        'name' => 'API', 'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'uuid-y', 'token' => 'tok'],
    ]);
    $instance = lifecycleInstance($row, ['connection_id' => $connection->id]);

    app(ApiwayService::class)->cancel($row);

    expect($row->fresh()->status)->toBe(ApiwaySubscriptionStatus::Cancelled)
        ->and($instance->fresh()->connection_id)->toBeNull()
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Inactive);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/cancel'));
});
