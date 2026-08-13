<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The realtime scoping is only worth anything if the REST read paths agree
 * with it. Before this, GET /conversations and GET /messages filtered by tenant
 * alone, so an agent assigned one connection could still pull the whole
 * tenant's inbox with a plain HTTP request — no WebSocket required.
 */
beforeEach(fn () => Http::fake());

function scopedWorld(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    Role::findOrCreate('owner', 'web');
    $owner->assignRole('owner');

    $mine = scopedConnection($tenant, 'Mine');
    $theirs = scopedConnection($tenant, 'Theirs');

    $agent = User::factory()->create(['tenant_id' => $tenant->id]);
    $agent->connections()->sync([$mine->id]);

    return [$tenant->fresh(), $owner->fresh(), $agent->fresh(), $mine, $theirs];
}

function scopedConnection(Tenant $tenant, string $name): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-'.$name, 'token' => 'tok'],
    ]);
}

function scopedThread(Connection $connection, string $externalId, string $body): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => $externalId,
        'name' => 'Contato '.$externalId,
        'channel' => $connection->channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $externalId,
        'status' => ConversationStatus::Pending,
        'last_message_at' => now(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);

    return $conversation->fresh();
}

test('the conversation list only returns connections the agent holds', function () {
    [, $owner, $agent, $mine, $theirs] = scopedWorld();

    $visible = scopedThread($mine, '5511000000001', 'para mim');
    $hidden = scopedThread($theirs, '5511000000002', 'nao para mim');

    Sanctum::actingAs($agent);
    $response = $this->getJson('/api/conversations')->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
    expect($ids)->toContain($visible->id)
        ->and($ids)->not->toContain($hidden->id);

    // The owner still sees both — they hold no pivot rows and must not be
    // narrowed by their absence.
    Sanctum::actingAs($owner);
    $ownerIds = collect($this->getJson('/api/conversations')->json('data'))
        ->pluck('id')->map(fn ($id) => (int) $id);

    expect($ownerIds)->toContain($visible->id)->toContain($hidden->id);
});

test('the message delta only returns messages from connections the agent holds', function () {
    [, , $agent, $mine, $theirs] = scopedWorld();

    scopedThread($mine, '5511000000001', 'para mim');
    scopedThread($theirs, '5511000000002', 'segredo do outro time');

    Sanctum::actingAs($agent);
    $bodies = collect($this->getJson('/api/messages')->assertOk()->json('data'))->pluck('body');

    expect($bodies)->toContain('para mim')
        ->and($bodies)->not->toContain('segredo do outro time');
});

test('the list states which connections the client may keep cached', function () {
    [, $owner, $agent, $mine, $theirs] = scopedWorld();

    Sanctum::actingAs($agent);
    expect($this->getJson('/api/conversations')->json('connection_ids'))
        ->toBe([(string) $mine->id]);

    // Owners get the tenant's full set, derived from the tenant rather than
    // from a pivot they deliberately have no rows in.
    Sanctum::actingAs($owner);
    expect($this->getJson('/api/conversations')->json('connection_ids'))
        ->toEqualCanonicalizing([(string) $mine->id, (string) $theirs->id]);
});

test('a conversation on an unheld connection is not reachable by id', function () {
    [, , $agent, , $theirs] = scopedWorld();

    $hidden = scopedThread($theirs, '5511000000002', 'nao para mim');

    Sanctum::actingAs($agent);

    $this->getJson("/api/conversations/{$hidden->id}")->assertNotFound();
    $this->getJson("/api/conversations/{$hidden->id}/variables")->assertNotFound();
    $this->getJson("/api/conversations/{$hidden->id}/participants")->assertNotFound();
    $this->postJson("/api/conversations/{$hidden->id}/accept")->assertNotFound();
    $this->postJson("/api/conversations/{$hidden->id}/mute")->assertNotFound();
});

test('the connection_id filter narrows a sync to one connection', function () {
    [, $owner, , $mine, $theirs] = scopedWorld();

    $a = scopedThread($mine, '5511000000001', 'um');
    $b = scopedThread($theirs, '5511000000002', 'dois');

    Sanctum::actingAs($owner);

    $ids = collect($this->getJson("/api/conversations?connection_id={$mine->id}")->json('data'))
        ->pluck('id')->map(fn ($id) => (int) $id);

    expect($ids)->toContain($a->id)->not->toContain($b->id);

    $bodies = collect($this->getJson("/api/messages?connection_id={$theirs->id}")->json('data'))
        ->pluck('body');

    expect($bodies)->toContain('dois')->not->toContain('um');
});

test('a thread cannot be transferred to an agent without access to its connection', function () {
    [$tenant, $owner, $agent, $mine] = scopedWorld();

    $conversation = scopedThread($mine, '5511000000001', 'oi');
    $conversation->update(['status' => ConversationStatus::Active, 'user_id' => $agent->id]);

    $stranger = User::factory()->create(['tenant_id' => $tenant->id]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/conversations/{$conversation->id}/transfer", ['agent_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'agent_missing_connection_access');

    // And they are not even offered as a target.
    $targets = collect($this->getJson("/api/conversations/{$conversation->id}/transfer-targets")->json('data'))
        ->pluck('id')->map(fn ($id) => (int) $id);

    expect($targets)->not->toContain($stranger->id)->toContain($owner->id);
});

test('losing a connection also loses threads still assigned to you', function () {
    [, , $agent, $mine] = scopedWorld();

    $conversation = scopedThread($mine, '5511000000001', 'oi');
    $conversation->update(['status' => ConversationStatus::Active, 'user_id' => $agent->id]);

    expect($conversation->load('connection')->isAccessibleBy($agent))->toBeTrue();

    // Revoke. Assignment alone must no longer keep the door open, otherwise
    // taking a connection away would leave every open thread readable.
    $agent->connections()->detach($mine->id);

    expect($conversation->load('connection')->isAccessibleBy($agent->fresh()))->toBeFalse();

    Sanctum::actingAs($agent->fresh());
    $this->getJson("/api/conversations/{$conversation->id}")->assertNotFound();
});
