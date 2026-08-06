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
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Proxy\ApiwayConfig;
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

test('a pix unit purchase quotes at ProxyBR and issues a payable invoice', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 2, 'cycle' => 'mensal', 'duration_days' => 30,
            'unit_price' => 49.9, 'total_price' => 99.8,
        ]]),
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 555, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR', 'qr_code_base64' => 'B64']],
        ]),
    ]);

    $tenant = apiwayTenant();

    $result = app(ApiwayService::class)->startUnitPurchase(
        $tenant, 2, 'mensal', 'br', PaymentMethod::Pix,
    );

    $row = $result['subscription'];
    $invoice = $result['invoice'];

    expect($row->status)->toBe(ApiwaySubscriptionStatus::PendingPayment)
        ->and($row->total_price_cents)->toBe(9980)
        ->and($invoice->purpose)->toBe(InvoicePurpose::ApiwayPurchase)
        ->and($invoice->subscription_id)->toBeNull()
        ->and($invoice->apiway_subscription_id)->toBe($row->id)
        ->and($invoice->pix_qr_code)->toBe('QR');
});

test('paying the pix invoice moves the row to provisioning and queues the job once', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 556, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
        ]),
    ]);

    $tenant = apiwayTenant();
    $result = app(ApiwayService::class)->startUnitPurchase($tenant, 1, 'mensal', 'br', PaymentMethod::Pix);
    $invoice = $result['invoice'];

    $payment = ['id' => '556', 'status' => 'approved'];
    app(BillingService::class)->applyPaymentUpdate($payment);
    app(BillingService::class)->applyPaymentUpdate($payment); // duplicate webhook

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($result['subscription']->fresh()->status)->toBe(ApiwaySubscriptionStatus::Provisioning);

    Bus::assertDispatchedTimes(ProvisionApiwaySubscription::class, 1);
});

// --- Unit purchase: card ---------------------------------------------------

test('a card unit purchase creates a preapproval and provisions on authorization', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'anual', 'unit_price' => 41.9, 'total_price' => 502.8,
        ]]),
        'api.mercadopago.com/preapproval' => Http::response(['id' => 'PA-77', 'status' => 'authorized']),
    ]);

    $tenant = apiwayTenant();

    $result = app(ApiwayService::class)->startUnitPurchase(
        $tenant, 1, 'anual', 'br', PaymentMethod::Card, 'card-token-1', 'apw@example.test',
    );

    $row = $result['subscription']->fresh();

    expect($result['authorized'])->toBeTrue()
        ->and($row->mp_preapproval_id)->toBe('PA-77')
        ->and($row->status)->toBe(ApiwaySubscriptionStatus::Provisioning)
        ->and($row->invoices()->where('purpose', InvoicePurpose::ApiwayPurchase->value)
            ->where('status', InvoiceStatus::Paid->value)->count())->toBe(1);

    Bus::assertDispatched(ProvisionApiwaySubscription::class);
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

test('abandoning an unpaid purchase voids the pix charge and deletes the row', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
        'api.mercadopago.com/v1/payments/557' => Http::response(['status' => 'cancelled']),
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 557, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
        ]),
    ]);

    $tenant = apiwayTenant();
    $result = app(ApiwayService::class)->startUnitPurchase($tenant, 1, 'mensal', 'br', PaymentMethod::Pix);
    $row = $result['subscription'];
    $invoice = $result['invoice'];

    expect(app(ApiwayService::class)->abandonPendingPurchase($row))->toBeTrue()
        ->and(ApiwaySubscription::find($row->id))->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->fresh()->apiway_subscription_id)->toBeNull();
});

test('a purchase that settled meanwhile refuses to be abandoned', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 558, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
        ]),
    ]);

    $tenant = apiwayTenant();
    $result = app(ApiwayService::class)->startUnitPurchase($tenant, 1, 'mensal', 'br', PaymentMethod::Pix);
    $row = $result['subscription'];

    // Pix approved before the user closed the modal.
    app(BillingService::class)->applyPaymentUpdate(['id' => '558', 'status' => 'approved']);

    expect(app(ApiwayService::class)->abandonPendingPurchase($row->fresh()))->toBeFalse()
        ->and($row->fresh()->status)->toBe(ApiwaySubscriptionStatus::Provisioning);
});

test('pending_payment purchases are hidden from the instance list', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/quote' => Http::response(['data' => [
            'quantity' => 1, 'cycle' => 'mensal', 'unit_price' => 49.9, 'total_price' => 49.9,
        ]]),
        'api.mercadopago.com/v1/payments' => Http::response([
            'id' => 559, 'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'QR']],
        ]),
    ]);

    $tenant = apiwayTenant();
    app(ApiwayService::class)->startUnitPurchase($tenant, 1, 'mensal', 'br', PaymentMethod::Pix);

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
