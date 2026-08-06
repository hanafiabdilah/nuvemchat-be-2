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

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_PARTNER_TOKEN, 'partner-token');
});

function connectTenant(): Tenant
{
    $user = User::factory()->create(['email' => 'cx-' . uniqid() . '@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function ownedInstance(Tenant $tenant, array $attributes = []): ApiwayInstance
{
    $row = ApiwaySubscription::create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-' . uniqid(),
        'provider_subscription_id' => random_int(1000, 999999),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    return ApiwayInstance::create(array_merge([
        'tenant_id' => $tenant->id,
        'apiway_subscription_id' => $row->id,
        'provider_instance_id' => 'uuid-' . uniqid(),
        'name' => 'Instancia',
        'status' => 'aguardando_qr',
    ], $attributes));
}

function apiwayConnection(Tenant $tenant): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'API ' . uniqid(),
        'status' => ConnectionStatus::Inactive,
    ]);
}

function fakeLinkSurface(string $instanceUuid): void
{
    Http::fake([
        "portal.proxybr.com.br/api/partner/v1/apiway/instances/{$instanceUuid}/token" => Http::response([
            'data' => ['token' => 'instance-token-1', 'masked' => 'inst***1'],
        ]),
        // available_events uses the partner's real shape: objects, not strings.
        "portal.proxybr.com.br/api/partner/v1/apiway/instances/{$instanceUuid}/webhook" => Http::response([
            'data' => ['url' => null, 'events' => [], 'available_events' => [
                ['value' => 'Message', 'label' => 'Mensagem recebida'],
                ['value' => 'Receipt', 'label' => 'Status de entrega'],
            ]],
        ]),
        'whats-api.ipbr.pro/v1/instance/qr-code*' => Http::response([
            'success' => true, 'data' => ['qrcode' => 'data:image/png;base64,QR'],
        ]),
        'whats-api.ipbr.pro/v1/instance/status-instance*' => Http::response([
            'success' => true, 'data' => ['connected' => false, 'loggedIn' => false],
        ]),
    ]);
}

test('connect links an owned instance: partner token fetched, webhook registered, QR retrieved', function () {
    $tenant = connectTenant();
    $instance = ownedInstance($tenant);
    $connection = apiwayConnection($tenant);

    fakeLinkSurface($instance->provider_instance_id);

    (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id, 'import_history' => true]);

    $connection->refresh();
    $instance->refresh();

    expect($instance->connection_id)->toBe($connection->id)
        ->and($connection->status)->toBe(ConnectionStatus::Pending)
        ->and($connection->credentials['instance_id'])->toBe($instance->provider_instance_id)
        ->and($connection->credentials['token'])->toBe('instance-token-1')
        ->and($connection->credentials['apiway_instance_id'])->toBe($instance->id)
        ->and($connection->credentials['import_history'])->toBeTrue()
        ->and($connection->credentials['qr_code'])->toBe('data:image/png;base64,QR');

    // The webhook PUT carried our chat endpoint + the available events as
    // plain names — the partner validates events.* as strings, so sending
    // the raw {value, label} objects would 400 and leave the webhook unset.
    Http::assertSent(function ($request) use ($connection) {
        return str_contains($request->url(), '/webhook')
            && $request->method() === 'PUT'
            && $request['url'] === route('webhook.chat', ['id' => $connection->id])
            && $request['events'] === ['Message', 'Receipt'];
    });
});

test('an instance owned by another connection cannot be linked', function () {
    $tenant = connectTenant();
    $other = apiwayConnection($tenant);
    $instance = ownedInstance($tenant, ['connection_id' => $other->id]);
    $connection = apiwayConnection($tenant);

    try {
        (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id]);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(422);
    }
});

test('an instance from an expired subscription cannot be linked', function () {
    $tenant = connectTenant();
    $instance = ownedInstance($tenant);
    $instance->subscription->update(['status' => ApiwaySubscriptionStatus::Expired]);
    $connection = apiwayConnection($tenant);

    try {
        (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id]);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(422);
    }
});

test('another tenant\'s instance is invisible', function () {
    $tenant = connectTenant();
    $stranger = connectTenant();
    $instance = ownedInstance($stranger);
    $connection = apiwayConnection($tenant);

    try {
        (new WhatsappApiwayChannel)->connect($connection, ['apiway_instance_id' => $instance->id]);
        $this->fail('Expected ConnectionException');
    } catch (AppConnectionException $e) {
        expect($e->getHttpStatusCode())->toBe(404);
    }
});

test('deleting the connection releases the instance back to the pool without touching the provider', function () {
    $tenant = connectTenant();
    $connection = apiwayConnection($tenant);
    $instance = ownedInstance($tenant, ['connection_id' => $connection->id]);
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
    $tenant = connectTenant();
    $connection = apiwayConnection($tenant);
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
