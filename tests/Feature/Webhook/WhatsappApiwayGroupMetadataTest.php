<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\Group\ApiwayGroupClient;
use App\Services\Conversation\Group\GroupMetadataSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const APW_META_GROUP_JID = '120363419920035031@g.us';

function apiwayMetadataConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'API Way',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'inst-1', 'token' => 'instance-token'],
    ]);
}

/** A group contact still carrying the JID placeholder the ingest path creates. */
function placeholderGroup(Connection $connection, ?string $name = null): Contact
{
    return Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => APW_META_GROUP_JID,
        'name' => $name ?? explode('@', APW_META_GROUP_JID)[0],
        'channel' => $connection->channel,
        'is_group' => true,
    ]);
}

test('the core subject replaces the JID placeholder', function () {
    Event::fake();
    Http::fake([
        '*/v1/group/group-metadata*' => Http::response(['success' => true, 'data' => [
            'JID' => APW_META_GROUP_JID,
            'Name' => 'Equipe Suporte',
            'Participants' => [],
        ]]),
    ]);

    $connection = apiwayMetadataConnection();
    $group = placeholderGroup($connection);

    expect(app(GroupMetadataSyncer::class)->sync($group, $connection))->toBeTrue();

    $group->refresh();
    expect($group->name)->toBe('Equipe Suporte')
        ->and($group->group_synced_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'instanceId=inst-1')
        && str_contains(urldecode($request->url()), 'groupId=' . APW_META_GROUP_JID)
        && $request->hasHeader('Authorization', 'Bearer instance-token'));
});

test('a name set by an agent is never overwritten by the core', function () {
    Event::fake();
    Http::fake(['*/v1/group/group-metadata*' => Http::response(['success' => true, 'data' => ['Name' => 'Equipe Suporte']])]);

    $connection = apiwayMetadataConnection();
    $group = placeholderGroup($connection, 'Meu nome');
    $group->forceFill(['name_locked' => true])->save();

    // Locked groups are not even worth a request.
    expect(GroupMetadataSyncer::isStale($group))->toBeFalse();

    app(GroupMetadataSyncer::class)->sync($group, $connection);

    expect($group->fresh()->name)->toBe('Meu nome');
});

test('a failed lookup keeps the current name and backs off', function () {
    Event::fake();
    Http::fake(['*/v1/group/group-metadata*' => Http::response(['error' => 'boom'], 500)]);

    $connection = apiwayMetadataConnection();
    $group = placeholderGroup($connection);

    expect(app(GroupMetadataSyncer::class)->sync($group, $connection))->toBeFalse();

    $group->refresh();
    expect($group->name)->toBe('120363419920035031')
        // Retried in ~an hour, not on the very next inbound message.
        ->and($group->group_synced_at)->not->toBeNull()
        ->and(GroupMetadataSyncer::isStale($group))->toBeFalse();
});

test('a group with no subject is left alone and not re-read all day', function () {
    Event::fake();
    Http::fake(['*/v1/group/group-metadata*' => Http::response(['success' => true, 'data' => ['JID' => APW_META_GROUP_JID]])]);

    $connection = apiwayMetadataConnection();
    $group = placeholderGroup($connection);

    expect(app(GroupMetadataSyncer::class)->sync($group, $connection))->toBeFalse()
        ->and($group->fresh()->name)->toBe('120363419920035031')
        ->and(GroupMetadataSyncer::isStale($group->fresh()))->toBeFalse();
});

// The collection documents `{success, data}` but stubs `data` as an empty
// string, so the real casing is unknown until it runs against the live core —
// every shape whatsmeow has been seen to marshal is accepted.
test('the subject is read whatever casing the core answers with', function (array $data, ?string $expected) {
    Http::fake(['*/v1/group/group-metadata*' => Http::response(['success' => true, 'data' => $data])]);

    expect((new ApiwayGroupClient)->name(apiwayMetadataConnection(), APW_META_GROUP_JID))->toBe($expected);
})->with([
    'whatsmeow PascalCase' => [['JID' => APW_META_GROUP_JID, 'Name' => 'Vendas'], 'Vendas'],
    'camelCase' => [['name' => 'Vendas'], 'Vendas'],
    'subject key' => [['subject' => 'Vendas'], 'Vendas'],
    'nested GroupName' => [['GroupName' => ['Name' => 'Vendas']], 'Vendas'],
    'no subject' => [[], null],
    'blank subject' => [['Name' => '   '], null],
]);

test('get-all-groups backfills every placeholder in one call', function () {
    Event::fake();
    Http::fake([
        '*/v1/group/get-all-groups*' => Http::response(['success' => true, 'data' => [
            ['JID' => APW_META_GROUP_JID, 'Name' => 'Equipe Suporte'],
            ['JID' => '555491607349-1623173607@g.us', 'Name' => 'Família'],
            ['JID' => '120363304932287424@g.us', 'Name' => 'Clientes VIP'],
        ]]),
    ]);

    $connection = apiwayMetadataConnection();
    placeholderGroup($connection);
    Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '555491607349-1623173607@g.us',
        'name' => '555491607349-1623173607',
        'channel' => $connection->channel,
        'is_group' => true,
    ]);

    $this->artisan('groups:sync-names')->assertSuccessful();

    expect(Contact::where('external_id', APW_META_GROUP_JID)->first()->name)->toBe('Equipe Suporte')
        ->and(Contact::where('external_id', '555491607349-1623173607@g.us')->first()->name)->toBe('Família')
        // The core lists groups we have no thread for; those create nothing.
        ->and(Contact::where('external_id', '120363304932287424@g.us')->exists())->toBeFalse();
});

test('a dry run reports without writing', function () {
    Event::fake();
    Http::fake([
        '*/v1/group/get-all-groups*' => Http::response(['data' => [
            ['JID' => APW_META_GROUP_JID, 'Name' => 'Equipe Suporte'],
        ]]),
    ]);

    $connection = apiwayMetadataConnection();
    placeholderGroup($connection);

    $this->artisan('groups:sync-names --dry-run')->assertSuccessful();

    expect(Contact::where('external_id', APW_META_GROUP_JID)->first()->name)->toBe('120363419920035031');
});
