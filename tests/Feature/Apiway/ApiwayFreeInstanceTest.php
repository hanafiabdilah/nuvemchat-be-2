<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Exceptions\Apiway\NumberPreparingException;
use App\Exceptions\ConnectionException as AppConnectionException;
use App\Jobs\ProvisionApiwaySubscription;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\ApiwayFixtures;

/*
 * Instances are free since Nov 2026: connecting a "WhatsApp via API Way"
 * connection gets it one — a spare the workspace already has, or a new one
 * made at ProxyBR — and the customer never buys, picks or sees an instance.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

function freeInstancePlan(Tenant $tenant, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
{
    $quotas = ['max_whatsapp_connections' => 3];

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 14990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'quotas' => $quotas, 'features' => ['chat' => true],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => $status, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 14990,
        'quotas_snapshot' => $quotas, 'features_snapshot' => ['chat' => true],
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    return $subscription;
}

/** The workspace owner, signed in with the permission the connect route asks for. */
function actingAsConnector(Tenant $tenant): void
{
    $tenant->user->givePermissionTo(Permission::findOrCreate('connections.connect', 'web'));
    Sanctum::actingAs($tenant->user);
}

/**
 * The catalog (for the default location) and the create call. With no body
 * the create succeeds with one instance; with a body it answers that, at
 * $status.
 */
function fakeFreeProvision(?array $body = null, int $status = 201): void
{
    $body ??= [
        'data' => [
            'id' => 42, 'platform' => 'pingly', 'quantity' => 1, 'cycle' => 'mensal',
            'unit_price' => 49.9, 'total_price' => 49.9, 'status' => 'active',
            'expires_at' => now()->addDays(30)->toISOString(),
            'instances' => [
                ['id' => 'uuid-core-1', 'name' => 'Instancia 01', 'status' => 'aguardando_qr', 'ip_address' => '10.0.0.9'],
            ],
        ],
        'meta' => ['idempotent_replay' => false],
    ];

    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/plans' => Http::response(['data' => [
            'locations' => [['id' => 1, 'public_code' => 'br', 'label' => 'Brasil', 'active' => true]],
        ]]),
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response($body, $status),
    ]);
}

test('connecting with no spare makes a free instance at ProxyBR and links it', function () {
    $tenant = ApiwayFixtures::tenant();
    freeInstancePlan($tenant);
    $connection = ApiwayFixtures::connection($tenant);

    fakeFreeProvision();
    ApiwayFixtures::fakeLinkSurface('uuid-core-1');

    (new WhatsappApiwayChannel)->connect($connection, []);

    $row = ApiwaySubscription::sole();
    $instance = ApiwayInstance::sole();

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Active)
        ->and($row->source)->toBe(ApiwaySubscriptionSource::PlanIncluded)
        ->and($row->quantity)->toBe(1)
        ->and($row->meta['for_connection_id'])->toBe($connection->id)
        ->and($instance->connection_id)->toBe($connection->id)
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Pending)
        ->and($connection->fresh()->credentials['qr_code'])->toBe('data:image/png;base64,QR');

    // Free: nothing touched the balance.
    expect(CreditTransaction::count())->toBe(0);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/apiway/subscriptions')
        && $request['quantity'] === 1
        && $request['location_code'] === 'br');
});

test('a spare instance is used before a new one is ordered', function () {
    $tenant = ApiwayFixtures::tenant();
    freeInstancePlan($tenant);
    $spare = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::connection($tenant);

    ApiwayFixtures::fakeLinkSurface($spare->provider_instance_id);

    (new WhatsappApiwayChannel)->connect($connection, []);

    expect($spare->fresh()->connection_id)->toBe($connection->id)
        ->and(ApiwaySubscription::count())->toBe(1);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/apiway/subscriptions'));
});

test('among spares, the one made for this connection wins', function () {
    $tenant = ApiwayFixtures::tenant();
    $other = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::connection($tenant);
    $mine = ApiwayFixtures::ownedInstance($tenant);
    $mine->subscription->update(['meta' => ['for_connection_id' => $connection->id]]);

    expect(app(ApiwayService::class)->instanceFor($connection)->id)->toBe($mine->id)
        ->and($other->fresh()->connection_id)->toBeNull();
});

test('while ProxyBR is still making it, connect says "preparing" and does not order a second one', function () {
    $tenant = ApiwayFixtures::tenant();
    freeInstancePlan($tenant);
    $connection = ApiwayFixtures::connection($tenant);
    actingAsConnector($tenant);

    // A 503 is retriable: the row stays provisioning and the job takes over.
    fakeFreeProvision(['message' => 'down'], 503);

    $this->postJson("/api/connections/{$connection->id}/connect", [])
        ->assertStatus(202)
        ->assertJsonPath('code', NumberPreparingException::CODE);

    Bus::assertDispatched(ProvisionApiwaySubscription::class);

    $this->postJson("/api/connections/{$connection->id}/connect", [])
        ->assertStatus(202)
        ->assertJsonPath('code', NumberPreparingException::CODE);

    expect(ApiwaySubscription::count())->toBe(1)
        ->and(ApiwaySubscription::sole()->status)->toBe(ApiwaySubscriptionStatus::Provisioning);

    Http::assertSentCount(2); // catalog + one create, never a second create
});

test('without a usable plan no instance is made', function () {
    $tenant = ApiwayFixtures::tenant();
    freeInstancePlan($tenant, SubscriptionStatus::Suspended);
    $connection = ApiwayFixtures::connection($tenant);

    try {
        app(ApiwayService::class)->instanceFor($connection);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(403);
    }

    expect(ApiwaySubscription::count())->toBe(0);
    Http::assertNothingSent();
});

test('a refusal from ProxyBR is reported in our own words and tells the customer nothing else', function () {
    $tenant = ApiwayFixtures::tenant();
    freeInstancePlan($tenant);
    $connection = ApiwayFixtures::connection($tenant);
    actingAsConnector($tenant);

    // A free instance that failed is reported in the wizard, not by message.
    $this->mock(BillingNotifier::class)->shouldNotReceive('notifyTenant');

    fakeFreeProvision(['message' => 'Sem IPv4 disponivel', 'error' => 'no_enabled_subnet_capacity'], 422);

    $response = $this->postJson("/api/connections/{$connection->id}/connect", [])->assertStatus(502);

    expect($response->json('message'))->not->toContain('IPv4')
        ->and($response->json('message'))->not->toContain('instância')
        ->and(ApiwaySubscription::sole()->status)->toBe(ApiwaySubscriptionStatus::Failed);
});

test('provision is idempotent for an already-active row', function () {
    $tenant = ApiwayFixtures::tenant();
    $row = ApiwaySubscription::create([
        'tenant_id' => $tenant->id, 'external_ref' => 'pingly-apw-done-'.uniqid(),
        'source' => ApiwaySubscriptionSource::Unit, 'cycle' => 'mensal', 'quantity' => 1,
        'location_code' => 'br', 'status' => ApiwaySubscriptionStatus::Active,
        'provider_subscription_id' => 42,
    ]);

    // No Http fake needed: an active row must never call the partner again.
    app(ApiwayService::class)->provision($row);

    expect($row->fresh()->status)->toBe(ApiwaySubscriptionStatus::Active);
});

test('the tenant routes that bought, listed and swapped instances are gone', function () {
    $tenant = ApiwayFixtures::tenant();
    $connection = ApiwayFixtures::connection($tenant);
    Sanctum::actingAs($tenant->user);

    $this->getJson('/api/apiway/instances')->assertNotFound();
    $this->postJson('/api/apiway/instances', ['mode' => 'included'])->assertNotFound();
    $this->getJson('/api/apiway/catalog')->assertNotFound();
    $this->postJson("/api/connections/{$connection->id}/apiway/instance", ['apiway_instance_id' => 1])->assertNotFound();
});
