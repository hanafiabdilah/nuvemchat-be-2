<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * `last_activity_at` feeds the "Última atividade" column on the Connections
 * page. It is a subselect on the list query only, which is the whole point of
 * the two cases below: every other endpoint returning a ConnectionResource
 * must leave the key out entirely rather than send null, because the SPA keeps
 * the value it already has when the key is absent. Sending null instead would
 * blank the column every time someone renamed a connection.
 */
beforeEach(fn () => Http::fake());

function activityWorld(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo(Permission::findOrCreate('connections.update', 'web'));
    $owner->assignRole('owner');

    return [$tenant->fresh(), $owner->fresh()];
}

function activityConnection(Tenant $tenant, string $name): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-'.$name, 'token' => 'tok'],
    ]);
}

function activityThread(Connection $connection, string $externalId, Carbon $lastMessageAt): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => $externalId,
        'name' => 'Contato '.$externalId,
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $externalId,
        'status' => ConversationStatus::Pending,
        'last_message_at' => $lastMessageAt,
    ]);
}

test('the list reports the most recent message across a connection threads', function () {
    [$tenant, $owner] = activityWorld();

    $busy = activityConnection($tenant, 'Busy');
    $quiet = activityConnection($tenant, 'Quiet');

    // Deliberately out of order, and the newest is not the last row created:
    // the column has to be a MAX, not "whatever the last join produced".
    activityThread($busy, '5511000000001', now()->subDays(3));
    activityThread($busy, '5511000000002', now()->subMinutes(5));
    activityThread($busy, '5511000000003', now()->subDay());

    Sanctum::actingAs($owner);
    $rows = collect($this->getJson('/api/connections')->assertOk()->json('data'))
        ->keyBy('name');

    expect($rows['Busy']['last_activity_at'])->not->toBeNull();
    expect(Carbon::parse($rows['Busy']['last_activity_at'])->timestamp)
        ->toBeGreaterThan(now()->subMinutes(6)->timestamp);

    // A connection nobody has written on yet reports null — the key is there,
    // the traffic is not.
    expect($rows)->toHaveKey('Quiet');
    expect($rows['Quiet']['last_activity_at'])->toBeNull();
    expect($quiet->conversations()->count())->toBe(0);
});

test('an agent sees last activity only for the connections they hold', function () {
    [$tenant, $owner] = activityWorld();

    $mine = activityConnection($tenant, 'Mine');
    $theirs = activityConnection($tenant, 'Theirs');
    activityThread($mine, '5511000000001', now()->subMinutes(2));
    activityThread($theirs, '5511000000002', now()->subMinutes(2));

    $agent = User::factory()->create(['tenant_id' => $tenant->id]);
    $agent->connections()->sync([$mine->id]);

    Sanctum::actingAs($agent->fresh());
    $rows = collect($this->getJson('/api/connections')->assertOk()->json('data'));

    // The subselect rides a belongsToMany here, where an explicit select can
    // quietly drop the pivot columns Eloquent adds for itself.
    expect($rows)->toHaveCount(1);
    expect($rows->first()['name'])->toBe('Mine');
    expect($rows->first()['last_activity_at'])->not->toBeNull();

    unset($owner, $theirs);
});

test('endpoints other than the list omit the key instead of nulling it', function () {
    [$tenant, $owner] = activityWorld();

    $connection = activityConnection($tenant, 'Renamed');
    activityThread($connection, '5511000000001', now()->subMinutes(2));

    Sanctum::actingAs($owner);
    $updated = $this->putJson("/api/connections/{$connection->id}", [
        'name' => 'Renamed twice',
    ])->assertOk()->json('data');

    expect($updated)->not->toHaveKey('last_activity_at');
});
