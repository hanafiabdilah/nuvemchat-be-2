<?php

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Enums\Connection\Channel;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BroadcastFixtures;

uses(RefreshDatabase::class);

test('picked contacts and pasted numbers become one de-duplicated recipient list', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana Souza');
    $bruno = BroadcastFixtures::contact($user, '5511999990002', 'Bruno Lima');

    $response = $this->actingAs($user)->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, [
        'contact_ids' => [$ana->id, $bruno->id],
        'manual_recipients' => [
            // Ana again, formatted the way a human pastes it — must collapse
            // into the contact row rather than message her twice.
            ['address' => '+55 (11) 99999-0001'],
            ['address' => '5511999990003', 'name' => 'Carla'],
            // Same new number twice, and one that is far too short to be real.
            ['address' => '5511999990003'],
            ['address' => '123'],
        ],
    ]));

    $response->assertCreated()->assertJsonPath('data.total', 3);

    $recipients = BroadcastRecipient::where('broadcast_id', $response->json('data.id'))->get();

    expect($recipients->pluck('address')->sort()->values()->all())->toBe([
        '5511999990001',
        '5511999990002',
        '5511999990003',
    ]);

    // First sighting wins: Ana keeps the contact she was picked as.
    expect($recipients->firstWhere('address', '5511999990001')->contact_id)->toBe($ana->id);
    expect($recipients->firstWhere('address', '5511999990003')->contact_id)->toBeNull();
    expect($recipients->every(fn ($r) => $r->status === RecipientStatus::Pending))->toBeTrue();
});

test('a campaign with no reachable recipient is refused rather than created empty', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);

    $this->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, [
            'manual_recipients' => [['address' => 'nope']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('recipients');

    expect(Broadcast::count())->toBe(0);
});

test('WhatsApp Official refuses free-form content, because a campaign lands outside the window', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $contact = BroadcastFixtures::contact($user, '5511999990001', 'Ana');

    $this->actingAs($user)
        ->postJson('/api/broadcasts', [
            'name' => 'Aviso',
            'connection_id' => $connection->id,
            'content_type' => 'text',
            'payload' => ['body' => 'Olá!'],
            'contact_ids' => [$contact->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('content_type');
});

test('templates are refused on channels that have no such thing', function () {
    $user = BroadcastFixtures::user();
    $telegram = BroadcastFixtures::connection($user, Channel::Telegram);

    $this->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($telegram, [
            'manual_recipients' => [['address' => '5511999990001']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('content_type');
});

test('a channel addressed by internal id takes no pasted recipients', function () {
    $user = BroadcastFixtures::user();
    $telegram = BroadcastFixtures::connection($user, Channel::Telegram);

    $this->actingAs($user)
        ->postJson('/api/broadcasts', [
            'name' => 'Aviso',
            'connection_id' => $telegram->id,
            'content_type' => 'text',
            'payload' => ['body' => 'Olá!'],
            'manual_recipients' => [['address' => '5511999990001']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('manual_recipients');
});

test('the rate cannot be raised past the channel cap', function () {
    $user = BroadcastFixtures::user();
    $apiway = BroadcastFixtures::connection($user, Channel::WhatsappApiway);
    $contact = BroadcastFixtures::contact($user, '5511999990001', 'Ana', Channel::WhatsappApiway);

    $this->actingAs($user)
        ->postJson('/api/broadcasts', [
            'name' => 'Aviso',
            'connection_id' => $apiway->id,
            'content_type' => 'text',
            'payload' => ['body' => 'Olá!'],
            'contact_ids' => [$contact->id],
            'rate_per_minute' => 600,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rate_per_minute');
});

test('a campaign defaults to the channel rate and starts as a draft', function () {
    $user = BroadcastFixtures::user();
    $apiway = BroadcastFixtures::connection($user, Channel::WhatsappApiway);
    $contact = BroadcastFixtures::contact($user, '5511999990001', 'Ana', Channel::WhatsappApiway);

    $response = $this->actingAs($user)->postJson('/api/broadcasts', [
        'name' => 'Aviso',
        'connection_id' => $apiway->id,
        'content_type' => 'text',
        'payload' => ['body' => 'Olá!'],
        'contact_ids' => [$contact->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', Status::Draft->value)
        ->assertJsonPath('data.rate_per_minute', Channel::WhatsappApiway->broadcastDefaultRatePerMinute());
});

test('another tenant cannot see or drive a campaign', function () {
    $owner = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($owner);
    $contact = BroadcastFixtures::contact($owner, '5511999990001', 'Ana');

    $id = $this->actingAs($owner)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, ['contact_ids' => [$contact->id]]))
        ->json('data.id');

    $stranger = BroadcastFixtures::user();

    $this->actingAs($stranger)->getJson("/api/broadcasts/{$id}")->assertNotFound();
    $this->actingAs($stranger)->postJson("/api/broadcasts/{$id}/start")->assertNotFound();
    $this->actingAs($stranger)->getJson('/api/broadcasts')->assertOk()->assertJsonCount(0, 'data');
});

test('editing replaces the recipient list, and is refused once the campaign is running', function () {
    $user = BroadcastFixtures::user();
    $connection = BroadcastFixtures::connection($user);
    $ana = BroadcastFixtures::contact($user, '5511999990001', 'Ana');

    $id = $this->actingAs($user)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, ['contact_ids' => [$ana->id]]))
        ->json('data.id');

    $this->actingAs($user)
        ->putJson("/api/broadcasts/{$id}", BroadcastFixtures::templateCampaign($connection, [
            'name' => 'Promo revisada',
            'manual_recipients' => [['address' => '5511999990002'], ['address' => '5511999990003']],
        ]))
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.name', 'Promo revisada');

    expect(BroadcastRecipient::where('broadcast_id', $id)->pluck('address')->sort()->values()->all())
        ->toBe(['5511999990002', '5511999990003']);

    Broadcast::whereKey($id)->update(['status' => Status::Running]);

    $this->actingAs($user)
        ->putJson("/api/broadcasts/{$id}", BroadcastFixtures::templateCampaign($connection, [
            'manual_recipients' => [['address' => '5511999990004']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('permissions are enforced separately for drafting and for firing', function () {
    $drafter = BroadcastFixtures::user(['broadcasts.view', 'broadcasts.create']);
    $connection = BroadcastFixtures::connection($drafter);
    $contact = BroadcastFixtures::contact($drafter, '5511999990001', 'Ana');

    $id = $this->actingAs($drafter)
        ->postJson('/api/broadcasts', BroadcastFixtures::templateCampaign($connection, ['contact_ids' => [$contact->id]]))
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($drafter)->postJson("/api/broadcasts/{$id}/start")->assertForbidden();
});
