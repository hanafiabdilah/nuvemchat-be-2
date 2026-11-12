<?php

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Exceptions\ConnectionException as AppConnectionException;
use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Models\Setting;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiwayFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
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

test('a released connection gets a replacement through the normal connect path', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::linkedConnection($instance);

    WhatsappApiwayChannel::releaseCredentials($connection, 'subscription_cancelled');
    $connection->refresh();

    $replacement = ApiwayFixtures::ownedInstance($tenant);
    ApiwayFixtures::fakeLinkSurface($replacement->provider_instance_id);

    // No stored instance_id any more, so connect() takes the link branch again
    // — which is the whole point of moving the credentials aside — and picks
    // up the workspace's spare instance on its own.
    (new WhatsappApiwayChannel)->connect($connection, []);

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
            ->and($e->getMessage())->toContain('não está disponível no momento')
            ->and($e->getMessage())->not->toContain('instância');
    }
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
