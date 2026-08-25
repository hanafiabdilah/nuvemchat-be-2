<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Exceptions\ApiwayPartnerException;
use App\Jobs\ProvisionApiwaySubscription;
use App\Models\ApiwaySubscription;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * ProxyBR gained a per-platform instance ceiling (partner security review,
 * 2026-08-24). It answers `422 platform_capacity_reached` — a 4xx like every
 * other validation refusal on that surface, but the request is fine and the
 * customer has already paid. Treating it as permanent would fail the purchase
 * and flag a manual refund for a limit an operator raises in one click.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

function capacityTenant(): Tenant
{
    $user = \App\Models\User::factory()->create([
        'email' => 'cap-'.uniqid().'@example.test',
        'whatsapp_verified_at' => now(),
    ]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function capacityRow(Tenant $tenant, array $overrides = []): ApiwaySubscription
{
    return ApiwaySubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-cap-'.uniqid(),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'unit_price_cents' => 4990,
        'total_price_cents' => 4990,
        'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Provisioning,
    ], $overrides));
}

function fakeCapacityRefusal(): void
{
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response([
            'error' => 'platform_capacity_reached',
            'message' => 'Teto de instâncias da plataforma atingido.',
        ], 422),
    ]);
}

test('a platform capacity refusal holds the purchase instead of failing it', function () {
    fakeCapacityRefusal();

    $row = capacityRow(capacityTenant());

    expect(fn () => app(ApiwayService::class)->provision($row))
        ->toThrow(ApiwayPartnerException::class);

    $row->refresh();

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Provisioning)
        ->and($row->meta['needs_refund'] ?? null)->toBeNull()
        ->and($row->meta['capacity_hold']['code'])->toBe('platform_capacity_reached')
        ->and($row->meta['capacity_hold']['attempts'])->toBe(1);
});

test('the refusal is retriable so the provisioning job does not burn its attempts', function () {
    fakeCapacityRefusal();

    $row = capacityRow(capacityTenant());

    try {
        app(ApiwayService::class)->provision($row);
    } catch (ApiwayPartnerException $e) {
        expect($e->isCapacityHold())->toBeTrue()
            ->and($e->isRetriable())->toBeTrue();
    }
});

test('repeated refusals accumulate on the same hold rather than restarting it', function () {
    fakeCapacityRefusal();

    $row = capacityRow(capacityTenant());
    $service = app(ApiwayService::class);

    foreach (range(1, 3) as $ignored) {
        try {
            $service->provision($row);
        } catch (ApiwayPartnerException) {
            // expected
        }
        $row->refresh();
    }

    expect($row->meta['capacity_hold']['attempts'])->toBe(3)
        ->and($row->status)->toBe(ApiwaySubscriptionStatus::Provisioning);
});

test('a hold older than the window degrades into a refundable failure', function () {
    config(['services.apiway.capacity_hold_hours' => 24]);
    fakeCapacityRefusal();

    $row = capacityRow(capacityTenant(), [
        'meta' => ['capacity_hold' => [
            'code' => 'platform_capacity_reached',
            'message' => 'Teto atingido.',
            'since' => now()->subDays(2)->toISOString(),
            'attempts' => 9,
        ]],
    ]);

    try {
        app(ApiwayService::class)->provision($row);
    } catch (ApiwayPartnerException) {
        // expected
    }

    $row->refresh();

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Failed)
        ->and($row->meta['needs_refund'])->toBeTrue();
});

test('an included instance is held too, but never flagged for a refund', function () {
    fakeCapacityRefusal();

    $row = capacityRow(capacityTenant(), ['source' => ApiwaySubscriptionSource::PlanIncluded]);

    try {
        app(ApiwayService::class)->provision($row);
    } catch (ApiwayPartnerException) {
        // expected
    }

    $row->refresh();

    expect($row->meta['capacity_hold'])->not->toBeNull()
        ->and($row->meta['needs_refund'] ?? null)->toBeNull();
});

test('apiway:sync re-dispatches held purchases', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
    ]);

    $tenant = capacityTenant();

    $held = capacityRow($tenant, [
        'meta' => ['capacity_hold' => ['code' => 'platform_capacity_reached', 'since' => now()->toISOString(), 'attempts' => 1]],
    ]);
    // A row provisioning for ordinary reasons must not be poked.
    $plain = capacityRow($tenant);

    $result = app(ApiwayService::class)->syncStatuses($tenant);

    expect($result['retried'])->toBe(1);

    Bus::assertDispatched(ProvisionApiwaySubscription::class,
        fn ($job) => $job->apiwaySubscriptionId === $held->id);
    Bus::assertNotDispatched(ProvisionApiwaySubscription::class,
        fn ($job) => $job->apiwaySubscriptionId === $plain->id);
});

test('a successful provision clears the hold', function () {
    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions' => Http::response(['data' => [
            'id' => 4242,
            'status' => 'active',
            'unit_price' => 49.9,
            'total_price' => 49.9,
            'expires_at' => now()->addMonth()->toISOString(),
            'instances' => [['id' => 'uuid-cap-1', 'name' => 'inst', 'status' => 'active']],
        ]]),
    ]);

    $row = capacityRow(capacityTenant(), [
        'meta' => ['capacity_hold' => ['code' => 'platform_capacity_reached', 'since' => now()->toISOString(), 'attempts' => 4]],
    ]);

    app(ApiwayService::class)->provision($row);

    $row->refresh();

    expect($row->status)->toBe(ApiwaySubscriptionStatus::Active)
        ->and($row->meta['capacity_hold'] ?? null)->toBeNull();
});
