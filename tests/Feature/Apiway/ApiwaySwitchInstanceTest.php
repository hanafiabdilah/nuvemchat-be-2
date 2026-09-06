<?php

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Exceptions\ConnectionException as AppConnectionException;
use App\Enums\Connection\Channel;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Setting;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ApiwayFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

test('switching moves the connection onto the new instance and releases the old one', function () {
    $tenant = ApiwayFixtures::tenant();
    $old = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($old, ['import_history' => true]);
    $new = ApiwayFixtures::ownedInstance($tenant);

    ApiwayFixtures::fakeLinkSurface($new->provider_instance_id);
    Http::fake([
        'whats-api.ipbr.pro/v1/instance/disconnect*' => Http::response(['success' => true]),
    ]);

    (new WhatsappApiwayChannel)->switchInstance($connection, $new->id);

    $connection->refresh();

    expect($new->fresh()->connection_id)->toBe($connection->id)
        // The old asset goes back to the pool — it is still paid for.
        ->and($old->fresh()->connection_id)->toBeNull()
        ->and($connection->credentials['apiway_instance_id'])->toBe($new->id)
        ->and($connection->credentials['instance_id'])->toBe($new->provider_instance_id)
        ->and($connection->credentials['token'])->toBe('instance-token-1')
        // A fresh QR for the new instance; the old one would never scan.
        ->and($connection->credentials['qr_code'])->toBe('data:image/png;base64,QR')
        // The paired number belonged to the instance we just let go.
        ->and($connection->credentials)->not->toHaveKey('phone_number')
        // The import opt-in is about this connection, so it survives.
        ->and($connection->credentials['import_history'])->toBeTrue()
        ->and($connection->status)->toBe(ConnectionStatus::Pending);

    // The number we walked away from is logged out rather than left signed in
    // on an instance that is now in the pool.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/instance/disconnect')
        && str_contains($request->url(), $old->provider_instance_id));
});

test('a dead instance does not block the swap that replaces it', function () {
    $tenant = ApiwayFixtures::tenant();
    $old = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($old);
    $old->subscription->update(['status' => ApiwaySubscriptionStatus::Cancelled]);
    $new = ApiwayFixtures::ownedInstance($tenant);

    ApiwayFixtures::fakeLinkSurface($new->provider_instance_id);
    Http::fake([
        // Exactly what a revoked instance answers: its token opens nothing.
        'whats-api.ipbr.pro/v1/instance/disconnect*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    (new WhatsappApiwayChannel)->switchInstance($connection, $new->id);

    expect($connection->fresh()->credentials['apiway_instance_id'])->toBe($new->id);
});

test('switching to the instance already in use is refused', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);

    try {
        (new WhatsappApiwayChannel)->switchInstance($connection, $instance->id);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(422);
    }
});

test('switching to an instance held by another connection is refused and changes nothing', function () {
    $tenant = ApiwayFixtures::tenant();
    $old = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($old);

    $otherConnection = ApiwayFixtures::connection($tenant);
    $taken = ApiwayFixtures::ownedInstance($tenant, ['connection_id' => $otherConnection->id]);

    Http::fake([
        'whats-api.ipbr.pro/v1/instance/disconnect*' => Http::response(['success' => true]),
    ]);

    try {
        (new WhatsappApiwayChannel)->switchInstance($connection, $taken->id);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(422);
    }

    expect($taken->fresh()->connection_id)->toBe($otherConnection->id);
});

test('cancelling the subscription strips the connection credentials and says why', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance, ['import_history' => true]);

    Http::fake([
        'portal.proxybr.com.br/api/partner/v1/apiway/subscriptions/*/cancel' => Http::response(['data' => []]),
    ]);

    app(ApiwayService::class)->cancel($instance->subscription);

    $connection->refresh();
    $credentials = $connection->credentials;

    // Nothing left that pretends to still work: no token, and above all no QR
    // code — the core answers "not connected" for a revoked instance, so every
    // refresh used to fail with nothing on screen to explain it.
    expect($credentials)->not->toHaveKey('instance_id')
        ->and($credentials)->not->toHaveKey('token')
        ->and($credentials)->not->toHaveKey('qr_code')
        ->and($credentials)->not->toHaveKey('apiway_instance_id')
        ->and($connection->status)->toBe(ConnectionStatus::Inactive)
        // What replaces it: which instance went, and why.
        ->and($credentials['released_instance']['instance_id'])->toBe($instance->provider_instance_id)
        ->and($credentials['released_instance']['apiway_instance_id'])->toBe($instance->id)
        ->and($credentials['released_instance']['phone_number'])->toBe('5511999999999')
        ->and($credentials['released_instance']['reason'])->toBe('subscription_cancelled')
        // The opt-in belongs to the connection, not to the instance.
        ->and($credentials['import_history'])->toBeTrue();
});

test('a released connection can be linked to a replacement through the normal connect path', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);

    WhatsappApiwayChannel::releaseCredentials($connection, 'subscription_cancelled');
    $connection->refresh();

    $replacement = ApiwayFixtures::ownedInstance($tenant);
    ApiwayFixtures::fakeLinkSurface($replacement->provider_instance_id);

    // No stored instance_id any more, so connect() takes the link branch again
    // — which is the whole point of moving the credentials aside.
    (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $replacement->id]);

    $connection->refresh();

    expect($connection->credentials['apiway_instance_id'])->toBe($replacement->id)
        ->and($connection->credentials['qr_code'])->toBe('data:image/png;base64,QR')
        ->and($connection->status)->toBe(ConnectionStatus::Pending);
});

test('a core that cannot produce a QR is reported instead of surfacing as a bare 500', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);
    // Force the QR branch: an instance that is not logged in yet.
    $connection->update(['status' => ConnectionStatus::Inactive]);

    Http::fake([
        'whats-api.ipbr.pro/v1/instance/update-webhook-*' => Http::response(['success' => true]),
        'whats-api.ipbr.pro/v1/instance/status-instance*' => Http::response([
            'success' => true, 'data' => ['connected' => false, 'loggedIn' => false],
        ]),
        // What a de-provisioned instance really answers.
        'whats-api.ipbr.pro/v1/instance/qr-code*' => Http::response(
            ['error' => 'node_error', 'message' => 'not connected'], 502,
        ),
    ]);

    try {
        (new WhatsappApiwayChannel)->connect($connection, []);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        // retry() must not throw past the branch that builds this message —
        // that is what turned this failure into a silent 500.
        expect($e->getHttpStatusCode())->toBe(502)
            ->and($e->getMessage())->toContain('não está ativa no provedor');
    }
});

test('the endpoint swaps the instance and hands back the refreshed connection', function () {
    $tenant = ApiwayFixtures::tenant();
    $old = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($old);
    $new = ApiwayFixtures::ownedInstance($tenant);

    ApiwayFixtures::fakeLinkSurface($new->provider_instance_id);
    Http::fake(['whats-api.ipbr.pro/v1/instance/disconnect*' => Http::response(['success' => true])]);

    $this->withoutMiddleware();
    Sanctum::actingAs($tenant->user);

    $this->postJson("/api/connections/{$connection->id}/apiway/instance", [
        'apiway_instance_id' => $new->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.credentials.apiway_instance_id', $new->id)
        ->assertJsonPath('data.credentials.instance_id', $new->provider_instance_id)
        // The instance token authorises the whole core surface; the SPA never
        // gets it, swap or no swap.
        ->assertJsonMissingPath('data.credentials.token');

    expect(AuditLog::where('action', 'connection.apiway_instance_switched')->count())->toBe(1);
});

test('the endpoint does not reach another tenant\'s connection', function () {
    $tenant = ApiwayFixtures::tenant();
    $stranger = ApiwayFixtures::tenant();
    $connection = ApiwayFixtures::linkedConnection(ApiwayFixtures::ownedInstance($stranger));
    $mine = ApiwayFixtures::ownedInstance($tenant);

    $this->withoutMiddleware();
    Sanctum::actingAs($tenant->user);

    $this->postJson("/api/connections/{$connection->id}/apiway/instance", [
        'apiway_instance_id' => $mine->id,
    ])->assertNotFound();
});

test('the endpoint refuses channels that have no instances', function () {
    $tenant = ApiwayFixtures::tenant();
    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Telegram,
        'name' => 'Bot',
        'status' => ConnectionStatus::Active,
    ]);

    $this->withoutMiddleware();
    Sanctum::actingAs($tenant->user);

    $this->postJson("/api/connections/{$connection->id}/apiway/instance", [
        'apiway_instance_id' => 1,
    ])->assertStatus(422);
});

/**
 * The one-off repair for connections cut loose before releaseInstances() knew
 * to clean up after itself. Selection is the risky half: releasing a working
 * inbox to tidy a column would take a channel offline for nothing.
 */
function runOrphanRepair(): void
{
    (require base_path('database/migrations/2026_09_06_000100_release_orphaned_apiway_connections.php'))->up();
}

test('the repair releases a connection whose subscription is gone', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);

    // What cancelling used to leave behind: the instance unlinked, the
    // connection still holding its id, token and a QR that cannot pair.
    $instance->update(['connection_id' => null]);
    $instance->subscription->update(['status' => ApiwaySubscriptionStatus::Cancelled]);

    runOrphanRepair();

    $credentials = $connection->fresh()->credentials;

    expect($credentials)->not->toHaveKey('qr_code')
        ->and($credentials)->not->toHaveKey('instance_id')
        ->and($credentials['released_instance']['reason'])->toBe('subscription_ended')
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Inactive);
});

test('the repair leaves a healthy connection alone', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);

    runOrphanRepair();

    expect($connection->fresh()->credentials['instance_id'])->toBe($instance->provider_instance_id)
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Active);
});

test('the repair restores the link rather than tearing down a live instance', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);

    // Paid, alive, working — only the back-reference is missing.
    $instance->update(['connection_id' => null]);

    runOrphanRepair();

    expect($instance->fresh()->connection_id)->toBe($connection->id)
        ->and($connection->fresh()->credentials['instance_id'])->toBe($instance->provider_instance_id)
        ->and($connection->fresh()->credentials)->not->toHaveKey('released_instance');
});

test('the repair ignores a connection that was never linked', function () {
    $tenant = ApiwayFixtures::tenant();
    $connection = ApiwayFixtures::connection($tenant);

    runOrphanRepair();

    expect($connection->fresh()->credentials)->toBeNull();
});
