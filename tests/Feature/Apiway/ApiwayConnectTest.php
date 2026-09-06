<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Exceptions\ConnectionException as AppConnectionException;
use App\Http\Resources\ConnectionResource;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Connection;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Connection\ConnectionService;
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

test('connect links an owned instance: partner token fetched, webhook registered, QR retrieved', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $connection = ApiwayFixtures::connection($tenant);

    ApiwayFixtures::fakeLinkSurface($instance->provider_instance_id);

    (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id, 'import_history' => true]);

    $connection->refresh();
    $instance->refresh();

    expect($instance->connection_id)->toBe($connection->id)
        ->and($instance->token)->toBe('instance-token-1')
        ->and($connection->status)->toBe(ConnectionStatus::Pending)
        ->and($connection->credentials['instance_id'])->toBe($instance->provider_instance_id)
        ->and($connection->credentials['token'])->toBe('instance-token-1')
        ->and($connection->credentials['apiway_instance_id'])->toBe($instance->id)
        ->and($connection->credentials['import_history'])->toBeTrue()
        ->and($connection->credentials['qr_code'])->toBe('data:image/png;base64,QR');

    // The inbound-message webhook was registered straight on the core with the
    // instance token, carrying both body shapes (legacy {value} + new {url}).
    Http::assertSent(function ($request) use ($connection) {
        return str_contains($request->url(), 'whats-api.ipbr.pro/v1/instance/update-webhook-received')
            && $request->method() === 'PUT'
            && $request->hasHeader('Authorization', 'Bearer instance-token-1')
            && $request['value'] === route('webhook.chat', ['id' => $connection->id])
            && $request['url'] === route('webhook.chat', ['id' => $connection->id]);
    });

    // Nothing webhook-related went through the partner console.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'portal.proxybr.com.br')
        && str_contains($request->url(), '/webhook'));
});

test('a logged-in status persists the paired WhatsApp number from the session jid', function () {
    $tenant = ApiwayFixtures::tenant();
    $connection = ApiwayFixtures::connection($tenant);
    $instance = ApiwayFixtures::ownedInstance($tenant, ['connection_id' => $connection->id]);
    $connection->update(['credentials' => [
        'instance_id' => $instance->provider_instance_id,
        'token' => 'instance-token-1',
        'apiway_instance_id' => $instance->id,
    ]]);

    Http::fake([
        'whats-api.ipbr.pro/v1/instance/status-instance*' => Http::response([
            'success' => true,
            'data' => ['connected' => true, 'loggedIn' => true, 'jid' => '5511999999999:73@s.whatsapp.net'],
        ]),
        'whats-api.ipbr.pro/v1/instance/disconnect*' => Http::response(['success' => true]),
    ]);

    (new WhatsappApiwayChannel)->checkStatus($connection);
    $connection->refresh();

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->credentials['phone_number'])->toBe('5511999999999');

    // Disconnect drops it — a new pairing may bring a different number.
    (new WhatsappApiwayChannel)->disconnect($connection);

    expect($connection->fresh()->credentials['phone_number'])->toBeNull();
});

test('an instance owned by another connection cannot be linked', function () {
    $tenant = ApiwayFixtures::tenant();
    $other = ApiwayFixtures::connection($tenant);
    $instance = ApiwayFixtures::ownedInstance($tenant, ['connection_id' => $other->id]);
    $connection = ApiwayFixtures::connection($tenant);

    try {
        (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id]);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(422);
    }
});

test('an instance from an expired subscription cannot be linked', function () {
    $tenant = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($tenant);
    $instance->subscription->update(['status' => ApiwaySubscriptionStatus::Expired]);
    $connection = ApiwayFixtures::connection($tenant);

    try {
        (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id]);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(422);
    }
});

test('another tenant\'s instance is invisible', function () {
    $tenant = ApiwayFixtures::tenant();
    $stranger = ApiwayFixtures::tenant();
    $instance = ApiwayFixtures::ownedInstance($stranger);
    $connection = ApiwayFixtures::connection($tenant);

    try {
        (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id]);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(404);
    }
});

test('deleting the connection releases the instance back to the pool without touching the provider', function () {
    $tenant = ApiwayFixtures::tenant();
    $connection = ApiwayFixtures::connection($tenant);
    $instance = ApiwayFixtures::ownedInstance($tenant, ['connection_id' => $connection->id]);
    $connection->update(['credentials' => [
        'instance_id' => $instance->provider_instance_id,
        'token' => 'instance-token-1',
        'apiway_instance_id' => $instance->id,
    ]]);

    // Only the session disconnect on the core is expected — never a delete.
    Http::fake(['whats-api.ipbr.pro/v1/instance/disconnect*' => Http::response(['success' => true])]);

    app(ConnectionService::class)->delete($connection);

    expect(Connection::find($connection->id))->toBeNull()
        ->and($instance->fresh()->connection_id)->toBeNull()
        ->and($instance->fresh()->subscription->status)->toBe(ApiwaySubscriptionStatus::Active);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'delete-instance'));
});

test('the connection resource never ships the instance API token to the SPA', function () {
    $tenant = ApiwayFixtures::tenant();
    $connection = ApiwayFixtures::connection($tenant);
    $connection->update(['credentials' => [
        'instance_id' => 'uuid-abc',
        'token' => 'super-secret',
        'apiway_instance_id' => 1,
        'qr_code' => 'data:image/png;base64,QR',
    ]]);

    $payload = (new ConnectionResource($connection->fresh()))->resolve();

    expect($payload['credentials'])->not->toHaveKey('token')
        ->and($payload['credentials']['instance_id'])->toBe('uuid-abc')
        ->and($payload['credentials']['qr_code'])->toBe('data:image/png;base64,QR');
});
