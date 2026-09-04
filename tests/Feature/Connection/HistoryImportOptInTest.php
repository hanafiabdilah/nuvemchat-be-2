<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The chat-history opt-in, asked for before the phone pairs.
 *
 * It used to be a field on connect, which put the question on the same screen
 * as the QR code — and the provider only exposes the chat list at the moment of
 * pairing, so after the scan the checkbox went on looking clickable while being
 * unable to change anything. This endpoint exists so the wizard can ask a step
 * earlier, and it refuses once the window has closed.
 */
function optInUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    Sanctum::actingAs($user);

    return $user;
}

function optInConnection(User $user, array $credentials = [], Channel $channel = Channel::WhatsappApiway): Connection
{
    return Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => $channel,
        'name' => 'API Way',
        'color' => '#22c55e',
        'status' => Status::Pending,
        'credentials' => $credentials,
    ]);
}

test('it stores the opt-in before the instance has ever paired', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    $connection = optInConnection($user, ['instance_id' => 'inst-1', 'token' => 'tok']);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertOk();

    expect($connection->fresh()->credentials['import_history'])->toBeTrue();
});

test('it keeps the rest of the credentials intact', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    $connection = optInConnection($user, ['instance_id' => 'inst-1', 'token' => 'tok']);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertOk();

    // The instance token lives in the same JSON column. Writing the flag with a
    // fresh array instead of a merge would unlink a live instance.
    expect($connection->fresh()->credentials)
        ->toMatchArray(['instance_id' => 'inst-1', 'token' => 'tok']);
});

test('it can turn the opt-in back off', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    $connection = optInConnection($user, ['import_history' => true]);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => false,
    ])->assertOk();

    expect($connection->fresh()->credentials['import_history'])->toBeFalse();
});

test('it refuses once an import has been queued', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    $connection = optInConnection($user, [
        'import_history' => false,
        'history_import' => ['status' => 'queued'],
    ]);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertStatus(422);

    // Storing a flag nothing will read is worse than saying no: the customer
    // would come back to a switch that is on and a history that never arrived.
    expect($connection->fresh()->credentials['import_history'])->toBeFalse();
});

test('it refuses once an import has finished', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    $connection = optInConnection($user, ['history_import' => ['status' => 'done']]);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertStatus(422);
});

test('a failed import can be opted into again', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    // 'failed' is not in the closed list on purpose: the pairing window may
    // still be open, and a transient core error should not cost the import.
    $connection = optInConnection($user, ['history_import' => ['status' => 'failed']]);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertOk();
});

test('it refuses on channels that have no chat list to import', function () {
    $this->withoutMiddleware();
    $user = optInUser();
    $connection = optInConnection($user, [], Channel::Telegram);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertStatus(422);
});

test('it does not reach another tenant\'s connection', function () {
    $this->withoutMiddleware();
    optInUser();

    $stranger = User::factory()->create();
    $strangerTenant = Tenant::create(['user_id' => $stranger->id]);
    $stranger->forceFill(['tenant_id' => $strangerTenant->id])->save();

    $connection = optInConnection($stranger);

    $this->putJson("/api/connections/{$connection->id}/history-import", [
        'import_history' => true,
    ])->assertStatus(404);
});
