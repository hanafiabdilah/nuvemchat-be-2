<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Event::fake([ConnectionUpdated::class]));

function statusSyncConnection(Status $status = Status::Active, array $credentials = ['instance_id' => 'inst-1', 'token' => 'tok-1']): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp vendas',
        'color' => '#22c55e',
        'status' => $status,
        'credentials' => $credentials,
    ]);
}

/** The core answering status-instance. */
function statusSyncCore(bool $connected, bool $loggedIn, int $httpStatus = 200): void
{
    Http::fake(['*/v1/instance/status-instance*' => Http::response(
        $httpStatus === 200
            ? ['success' => true, 'data' => ['connected' => $connected, 'loggedIn' => $loggedIn, 'jid' => '5511999990000:12@s.whatsapp.net']]
            : ['error' => 'node_error', 'message' => 'node unavailable'],
        $httpStatus,
    )]);
}

test('a number logged out from the phone is marked disconnected as soon as the core says so', function () {
    statusSyncCore(connected: false, loggedIn: false);
    $connection = statusSyncConnection();

    (new WhatsappApiwayHandler)->handle($connection, ['type' => 'LoggedOut', 'event' => ['OnConnect' => false, 'Reason' => 401]]);

    expect($connection->fresh()->status)->toBe(Status::Inactive);
    Event::assertDispatched(ConnectionUpdated::class, fn ($event) => $event->conn->id === $connection->id
        && $event->conn->status === Status::Inactive);
});

test('the event name is matched however the core spells it', function () {
    statusSyncCore(connected: false, loggedIn: false);
    $connection = statusSyncConnection();

    (new WhatsappApiwayHandler)->handle($connection, ['event' => 'logged_out', 'reason_code' => 401]);

    expect($connection->fresh()->status)->toBe(Status::Inactive);
});

test('pairing from the QR code turns a pending connection active, with its number', function () {
    statusSyncCore(connected: true, loggedIn: true);
    $connection = statusSyncConnection(Status::Pending);

    (new WhatsappApiwayHandler)->handle($connection, ['type' => 'PairSuccess', 'event' => ['ID' => '5511999990000:12@s.whatsapp.net']]);

    $connection->refresh();

    expect($connection->status)->toBe(Status::Active)
        ->and($connection->credentials['phone_number'])->toBe('5511999990000');
    Event::assertDispatched(ConnectionUpdated::class);
});

test('a connection waiting for its QR code stays pending', function () {
    statusSyncCore(connected: true, loggedIn: false);
    $connection = statusSyncConnection(Status::Pending);

    expect((new WhatsappApiwayChannel)->syncStatus($connection))->toBeFalse()
        ->and($connection->fresh()->status)->toBe(Status::Pending);
    Event::assertNotDispatched(ConnectionUpdated::class);
});

test('a paired session off the line only counts as disconnected once it stays off', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    statusSyncCore(connected: false, loggedIn: true);
    $connection = statusSyncConnection();
    $channel = new WhatsappApiwayChannel;

    expect($channel->syncStatus($connection))->toBeFalse();

    Carbon::setTestNow('2026-11-03 10:03:00');
    expect($channel->syncStatus($connection->fresh()))->toBeFalse()
        ->and($connection->fresh()->status)->toBe(Status::Active);

    Carbon::setTestNow('2026-11-03 10:06:00');
    expect($channel->syncStatus($connection->fresh()))->toBeTrue()
        ->and($connection->fresh()->status)->toBe(Status::Inactive);

    Carbon::setTestNow();
});

test('a reconnection inside the grace period never shows as a disconnection', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $answer = fn (bool $connected) => ['success' => true, 'data' => ['connected' => $connected, 'loggedIn' => true]];
    Http::fake(['*/v1/instance/status-instance*' => Http::sequence()
        ->push($answer(false))
        ->push($answer(true))
        ->push($answer(false))]);

    $connection = statusSyncConnection();
    $channel = new WhatsappApiwayChannel;

    $channel->syncStatus($connection);

    Carbon::setTestNow('2026-11-03 10:02:00');
    $channel->syncStatus($connection->fresh());

    // Off the line again much later: the clock starts over.
    Carbon::setTestNow('2026-11-03 10:20:00');

    expect($channel->syncStatus($connection->fresh()))->toBeFalse()
        ->and($connection->fresh()->status)->toBe(Status::Active);
    Event::assertNotDispatched(ConnectionUpdated::class);

    Carbon::setTestNow();
});

test('a core that does not answer changes nothing', function () {
    statusSyncCore(connected: false, loggedIn: false, httpStatus: 502);
    $connection = statusSyncConnection();

    expect((new WhatsappApiwayChannel)->syncStatus($connection))->toBeNull()
        ->and($connection->fresh()->status)->toBe(Status::Active);
    Event::assertNotDispatched(ConnectionUpdated::class);
});

test('the scheduled pass catches a disconnection no event announced, and skips unlinked connections', function () {
    statusSyncCore(connected: false, loggedIn: false);
    $linked = statusSyncConnection();
    $unlinked = statusSyncConnection(Status::Inactive, ['import_history' => true]);

    $this->artisan('apiway:sync-connection-status')->assertSuccessful();

    expect($linked->fresh()->status)->toBe(Status::Inactive)
        ->and($unlinked->fresh()->status)->toBe(Status::Inactive);
    Http::assertSentCount(1);
});

test('a number that comes back is marked connected again', function () {
    statusSyncCore(connected: true, loggedIn: true);
    $connection = statusSyncConnection(Status::Inactive);

    $this->artisan('apiway:sync-connection-status')->assertSuccessful();

    expect($connection->fresh()->status)->toBe(Status::Active);
    Event::assertDispatched(ConnectionUpdated::class);
});
