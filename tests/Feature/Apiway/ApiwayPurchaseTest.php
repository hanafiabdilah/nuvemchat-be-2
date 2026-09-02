<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Jobs\ProvisionApiwaySubscription;
use App\Models\ApiwaySubscription;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionGate;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Proxy\ApiwayConfig;
use App\Services\Credits\CreditService;
use App\Enums\Credit\CreditTransactionType;
use App\Exceptions\Billing\InsufficientCreditException;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

function apiwayTenant(): Tenant
{
    $user = User::factory()->create([
        'email' => 'apw-' . uniqid() . '@example.test',
        'whatsapp_verified_at' => now(),
    ]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function apiwayPlanSubscription(Tenant $tenant, array $quotas = ['included_instances' => 1], array $features = ['whatsapp_api' => true]): Subscription
{
    $plan = Plan::create([
        'name' => 'API Way', 'slug' => 'apiway-' . uniqid(), 'price_cents' => 4990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'quotas' => $quotas, 'features' => $features,
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 4990,
        'quotas_snapshot' => $quotas, 'features_snapshot' => $features,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription;
}

/** A tenant with money in the wallet — the only way to buy an instance now. */
function apiwayCredit(Tenant $tenant, int $cents): void
{
    CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $cents, 'currency' => 'BRL']);
}

/** Quote + create: the two partner calls a unit purchase makes, in order. */
function fakePartnerPurchase(): void
{
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response([
            'data' => [
                'id' => 42, 'platform' => 'pingly', 'quantity' => 1, 'cycle' => 'mensal',
                'unit_price' => 49.9, 'total_price' => 49.9, 'status' => 'active',
                'expires_at' => now()->addDays(30)->toISOString(),
                'instances' => [
                    ['id' => 'uuid-core-1', 'name' => 'Instancia 01', 'status' => 'aguardando_qr', 'ip_address' => '10.0.0.9'],
                ],
            ],
            'meta' => ['idempotent_replay' => false],
        ], 201),
    ]);
}

/**
 * A purchase in the shape the Pix flow used to leave behind: pending payment,
 * open invoice, no instance. Nothing creates these any more — they exist in
 * production from before the balance, and the code paths that finish them are
 * kept alive for exactly that reason.
 *
 * @return array{0: ApiwaySubscription, 1: Invoice}
 */
function legacyPendingPurchase(Tenant $tenant, string $paymentId): array
{
    $row = ApiwaySubscription::create([
        'tenant_id' => $tenant->id, 'external_ref' => 'pingly-apw-legacy-' . uniqid(),
        'source' => ApiwaySubscriptionSource::Unit, 'cycle' => 'mensal', 'quantity' => 1,
        'unit_price_cents' => 4990, 'total_price_cents' => 4990, 'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::PendingPayment,
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $tenant->id, 'apiway_subscription_id' => $row->id,
        'purpose' => InvoicePurpose::ApiwayPurchase, 'status' => InvoiceStatus::Pending,
        'payment_method' => PaymentMethod::Pix, 'amount_cents' => 4990, 'currency' => 'BRL',
        'due_date' => now()->addDay()->toDateString(), 'mp_payment_id' => $paymentId,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    return [$row, $invoice];
}

function fakePartnerCreate(array $overrides = []): void
{
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/plans' => Http::response(['data' => [
            'settings' => ['annual_discount_pct' => 30, 'min_instances' => 1, 'max_instances' => 20],
            'tiers' => [['id' => 1, 'min_qty' => 1, 'unit_price_monthly' => 49.9]],
            'locations' => [['id' => 1, 'public_code' => 'br', 'label' => 'Brasil', 'active' => true, 'surcharge' => 0]],
        ]]),
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response(array_merge([
            'data' => [
                'id' => 42, 'platform' => 'pingly', 'quantity' => 1, 'cycle' => 'mensal',
                'unit_price' => 49.9, 'total_price' => 49.9, 'status' => 'active',
                'expires_at' => now()->addDays(30)->toISOString(),
                'instances' => [
                    ['id' => 'uuid-core-1', 'name' => 'Instancia 01', 'status' => 'aguardando_qr', 'ip_address' => '10.0.0.9'],
                ],
            ],
            'meta' => ['idempotent_replay' => false],
        ], $overrides), 201),
    ]);
}

// --- Catalog ---------------------------------------------------------------

test('catalog returns 503 when the partner token is not configured', function () {
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, null);
    $tenant = apiwayTenant();
    Sanctum::actingAs($tenant->user()->first());

    $this->getJson('/api/apiway/catalog')
        ->assertStatus(503)
        ->assertJsonPath('code', 'apiway_unconfigured');
});

test('catalog passes the partner data through with the usage summary', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 2]);
    Sanctum::actingAs($tenant->user()->first());

    $this->getJson('/api/apiway/catalog')
        ->assertOk()
        ->assertJsonPath('data.tiers.0.unit_price_monthly', 49.9)
        ->assertJsonPath('data.usage.included_quota', 2)
        ->assertJsonPath('data.usage.included_used', 0);
});

// --- Included instances ----------------------------------------------------

test('an included instance provisions at ProxyBR without any charge', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 1]);

    $row = app(ApiwayService::class)->createIncludedInstance($tenant);

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Active)
        ->and($row->source)->toBe(ApiwaySubscriptionSource::PlanIncluded)
        ->and($row->external_ref)->toBe('pingly-apw-' . $row->id)
        ->and($row->provider_subscription_id)->toBe(42)
        ->and($row->instances)->toHaveCount(1)
        ->and($row->instances->first()->provider_instance_id)->toBe('uuid-core-1');

    expect(Invoice::count())->toBe(0);
});

test('the included allotment is enforced', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 1]);

    app(ApiwayService::class)->createIncludedInstance($tenant);

    app(ApiwayService::class)->createIncludedInstance($tenant);
})->throws(ValidationException::class);

test('included instances require a usable plan subscription', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();

    app(ApiwayService::class)->createIncludedInstance($tenant);
})->throws(ValidationException::class);

// --- Unit purchase: Pix ----------------------------------------------------

test('a unit purchase is charged to the balance and provisions straight away', function () {
    fakePartnerPurchase();
    $tenant = apiwayTenant();
    apiwayCredit($tenant, 20_000);

    $row = app(ApiwayService::class)->purchaseUnits($tenant, 1, 'mensal', 'br');

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Active)
        ->and($row->source)->toBe(ApiwaySubscriptionSource::Unit)
        ->and($row->total_price_cents)->toBe(4990)
        ->and($row->instances)->toHaveCount(1);

    // No invoice, no Pix QR, no pending state — the balance IS the payment.
    expect(Invoice::count())->toBe(0);

    $debit = CreditTransaction::where('tenant_id', $tenant->id)->sole();
    expect($debit->type)->toBe(CreditTransactionType::Purchase)
        ->and($debit->amount_cents)->toBe(-4990)
        ->and($debit->reference)->toBe("apiway:buy:{$row->id}")
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe(15_010);
});

test('a purchase the balance cannot cover is refused and leaves nothing behind', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
    ]);

    $tenant = apiwayTenant();
    apiwayCredit($tenant, 1_000);

    expect(fn () => app(ApiwayService::class)->purchaseUnits($tenant, 1, 'mensal', 'br'))
        ->toThrow(InsufficientCreditException::class);

    // Nothing half-made: no subscription row pretending an instance is coming,
    // and not a cent moved. ProxyBR was never called — the request would have
    // been a stray one, which this suite fails on.
    expect(ApiwaySubscription::count())->toBe(0)
        ->and(CreditTransaction::count())->toBe(0)
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe(1_000);
});

test('a purchase that fails to provision gives the money back to the balance', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response([
            'error' => 'no_enabled_subnet_capacity',
            'message' => 'Sem capacidade IPv4 disponível.',
        ], 422),
    ]);

    $tenant = apiwayTenant();
    apiwayCredit($tenant, 20_000);

    expect(fn () => app(ApiwayService::class)->purchaseUnits($tenant, 1, 'mensal', 'br'))
        ->toThrow(ValidationException::class);

    $row = ApiwaySubscription::sole();

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Failed)
        // The refund happened. Nothing is waiting for a human, which is the
        // whole difference from the Pix era.
        ->and($row->meta['needs_refund'] ?? null)->toBeNull()
        ->and($row->meta['refunded_cents'])->toBe(4990)
        ->and(app(CreditService::class)->balanceCents($tenant))->toBe(20_000);

    // Both halves stay on the statement: charged, then given back.
    expect(CreditTransaction::where('tenant_id', $tenant->id)->pluck('type')->all())
        ->toBe([CreditTransactionType::Purchase, CreditTransactionType::Reversal]);
});

// --- Provisioning failure --------------------------------------------------

test('a capacity failure marks the row failed and flags a refund for unit purchases', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response([
            'error' => 'no_enabled_subnet_capacity',
            'message' => 'Sem capacidade IPv4 disponível.',
        ], 422),
    ]);

    $tenant = apiwayTenant();
    $row = ApiwaySubscription::create([
        'tenant_id' => $tenant->id, 'external_ref' => 'pingly-apw-test-' . uniqid(),
        'source' => ApiwaySubscriptionSource::Unit, 'cycle' => 'mensal', 'quantity' => 1,
        'unit_price_cents' => 4990, 'total_price_cents' => 4990, 'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Provisioning,
    ]);

    try {
        app(ApiwayService::class)->provision($row);
        $this->fail('Expected ApiwayPartnerException');
    } catch (\App\Exceptions\ApiwayPartnerException $e) {
        expect($e->isRetriable())->toBeFalse();
    }

    $row->refresh();
    expect($row->status)->toBe(ApiwaySubscriptionStatus::Failed)
        ->and($row->meta['needs_refund'])->toBeTrue();
});

test('several included instances can be taken in one go', function () {
    fakePartnerCreate(['data' => [
        'id' => 42, 'platform' => 'pingly', 'quantity' => 3, 'cycle' => 'mensal',
        'unit_price' => 49.9, 'total_price' => 149.7, 'status' => 'active',
        'expires_at' => now()->addDays(30)->toISOString(),
        'instances' => [
            ['id' => 'uuid-core-1', 'name' => 'Instancia 01', 'status' => 'aguardando_qr'],
            ['id' => 'uuid-core-2', 'name' => 'Instancia 02', 'status' => 'aguardando_qr'],
            ['id' => 'uuid-core-3', 'name' => 'Instancia 03', 'status' => 'aguardando_qr'],
        ],
    ]]);

    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 4]);

    $row = app(ApiwayService::class)->createIncludedInstance($tenant, 'br', 3);

    // One subscription of three, not three subscriptions — the same shape the
    // paid path produces, and one partner call instead of three.
    expect($row->quantity)->toBe(3)
        ->and($row->instances)->toHaveCount(3)
        ->and(ApiwaySubscription::count())->toBe(1)
        ->and(Invoice::count())->toBe(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions')
        && $request['quantity'] === 3);

    expect(app(ApiwayService::class)->usageSummary($tenant->fresh())['included_used'])->toBe(3);
});

test('an included request beyond the remaining allowance is refused whole', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 2]);

    // Checking "is at least one free?" would let this through and provision
    // three against a two-instance plan, with nothing charged for the excess.
    expect(fn () => app(ApiwayService::class)->createIncludedInstance($tenant, 'br', 3))
        ->toThrow(ValidationException::class);

    expect(ApiwaySubscription::count())->toBe(0);
});

test('the included allowance counts what is already taken, not just rows', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 3]);

    app(ApiwayService::class)->createIncludedInstance($tenant, 'br', 2);
    app(SubscriptionGate::class)->forget($tenant);

    // Two of three spent by a single row: only one may follow.
    expect(fn () => app(ApiwayService::class)->createIncludedInstance($tenant->fresh(), 'br', 2))
        ->toThrow(ValidationException::class);
});

// --- Included with explicit location ---------------------------------------

test('an included instance can pick its location', function () {
    fakePartnerCreate();
    $tenant = apiwayTenant();
    apiwayPlanSubscription($tenant, ['included_instances' => 1]);

    $row = app(ApiwayService::class)->createIncludedInstance($tenant, 'us');

    expect($row->location_code)->toBe('us');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions')
        && $request['location_code'] === 'us');
});

// --- Abandoned checkouts ----------------------------------------------------

test('abandoning a legacy unpaid purchase voids the pix charge and deletes the row', function () {
    Http::fake(['api.mercadopago.com/v1/payments/557' => Http::response(['status' => 'cancelled'])]);

    $tenant = apiwayTenant();
    [$row, $invoice] = legacyPendingPurchase($tenant, '557');

    expect(app(ApiwayService::class)->abandonPendingPurchase($row))->toBeTrue()
        ->and(ApiwaySubscription::find($row->id))->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->fresh()->apiway_subscription_id)->toBeNull();
});

test('a legacy purchase that settled meanwhile refuses to be abandoned', function () {
    $tenant = apiwayTenant();
    [$row] = legacyPendingPurchase($tenant, '558');

    // Pix approved before the user closed the modal.
    app(BillingService::class)->applyPaymentUpdate(['id' => '558', 'status' => 'approved']);

    expect(app(ApiwayService::class)->abandonPendingPurchase($row->fresh()))->toBeFalse()
        ->and($row->fresh()->status)->toBe(ApiwaySubscriptionStatus::Provisioning);
});

test('legacy pending_payment purchases stay hidden from the instance list', function () {
    $tenant = apiwayTenant();
    legacyPendingPurchase($tenant, '559');

    Sanctum::actingAs($tenant->user()->first());

    $this->getJson('/api/apiway/instances')
        ->assertOk()
        ->assertJsonCount(0, 'pending_subscriptions');
});

test('provision is idempotent for an already-active row', function () {
    $tenant = apiwayTenant();
    $row = ApiwaySubscription::create([
        'tenant_id' => $tenant->id, 'external_ref' => 'pingly-apw-done-' . uniqid(),
        'source' => ApiwaySubscriptionSource::Unit, 'cycle' => 'mensal', 'quantity' => 1,
        'location_code' => 'br', 'status' => ApiwaySubscriptionStatus::Active,
        'provider_subscription_id' => 42,
    ]);

    // No Http fake needed: an active row must never call the partner again.
    app(ApiwayService::class)->provision($row);

    expect($row->fresh()->status)->toBe(ApiwaySubscriptionStatus::Active);
});
