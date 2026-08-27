<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function liveOwner(array $permissions = ['statistics.tenant.view', 'statistics.agents.view']): User
{
    $user = User::factory()->create(['name' => 'Owner']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user->assignRole($role);

    return $user->fresh();
}

function liveConnection(User $user, string $name = 'Vendas'): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappOfficial,
        'name' => $name,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function liveConversation(Connection $connection, array $attributes = [], string $contactName = 'João Pereira'): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '5511987654321',
        'name' => $contactName,
        'channel' => $connection->channel,
    ]);

    return Conversation::create(array_merge([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
        'last_message_at' => now(),
    ], $attributes));
}

function liveMessage(Conversation $conversation, SenderType $sender, ?Carbon $at = null, array $attributes = []): Message
{
    $at ??= now();

    $message = Message::create(array_merge([
        'conversation_id' => $conversation->id,
        'sender_type' => $sender,
        'message_type' => MessageType::Text,
        'body' => 'segredo do cliente',
        'sent_at' => $at,
    ], $attributes));

    $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $message->fresh();
}

it('answers for a workspace with no traffic at all', function () {
    $user = liveOwner();

    $data = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data');

    expect($data['events'])->toBe([])
        ->and($data['pulse']['inbound_60s'])->toBe(0)
        ->and($data['pulse']['series'])->toHaveCount(15)
        // The roster still lists the owner, offline, rather than coming back
        // empty — "nobody is staffing this" is the answer, not the absence of one.
        ->and($data['agents'])->toHaveCount(1)
        ->and($data['agents'][0]['presence'])->toBe('offline');
});

it('never puts message content on the wire', function () {
    $user = liveOwner();
    $conversation = liveConversation(liveConnection($user));
    liveMessage($conversation, SenderType::Incoming);

    $body = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->getContent();

    expect($body)->not->toContain('segredo do cliente');
});

it('streams inbound and outbound with who sent them', function () {
    $user = liveOwner();
    $agent = User::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Ana Souza']);
    $connection = liveConnection($user);
    $conversation = liveConversation($connection, ['user_id' => $agent->id, 'status' => ConversationStatus::Active]);

    liveMessage($conversation, SenderType::Incoming);
    liveMessage($conversation, SenderType::Outgoing, null, ['sent_by_user_id' => $agent->id]);

    $events = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data.events');

    expect($events)->toHaveCount(2)
        ->and($events[0]['direction'])->toBe('in')
        ->and($events[0]['actor'])->toBe(['kind' => 'contact', 'name' => 'João Pereira'])
        ->and($events[0]['status'])->toBeNull()
        ->and($events[0]['connection']['name'])->toBe('Vendas')
        ->and($events[0]['channel'])->toBe('whatsapp_official')
        // The tenant surface is not masked: this is the workspace's own customer.
        ->and($events[0]['contact']['handle'])->toBe('5511987654321')
        ->and($events[1]['direction'])->toBe('out')
        ->and($events[1]['actor'])->toBe(['kind' => 'agent', 'name' => 'Ana Souza'])
        ->and($events[1]['status'])->toBe('sent');
});

it('tells an automated reply apart from an agent and from an authorless one', function () {
    $user = liveOwner();
    $connection = liveConnection($user);
    $conversation = liveConversation($connection);
    $flow = Flow::create(['name' => 'Boas-vindas', 'tenant_id' => $user->tenant_id]);

    liveMessage($conversation, SenderType::Outgoing, null, ['sent_by_flow_id' => $flow->id]);
    liveMessage($conversation, SenderType::Outgoing);

    $events = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data.events');

    expect($events[0]['actor'])->toBe(['kind' => 'flow', 'name' => 'Boas-vindas'])
        ->and($events[1]['actor'])->toBe(['kind' => 'system', 'name' => null]);
});

it('leaves system notes out of the outbound lane', function () {
    $user = liveOwner();
    $conversation = liveConversation(liveConnection($user));

    liveMessage($conversation, SenderType::Outgoing, null, ['message_type' => MessageType::Info]);
    liveMessage($conversation, SenderType::Incoming);

    $events = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data.events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['direction'])->toBe('in');
});

it('returns only what happened after the cursor, and holds it when nothing did', function () {
    $user = liveOwner();
    $conversation = liveConversation(liveConnection($user));

    $first = liveMessage($conversation, SenderType::Incoming);

    $cursor = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data.cursor');
    expect($cursor)->toBe($first->id);

    $second = liveMessage($conversation, SenderType::Outgoing);

    $delta = $this->actingAs($user)->getJson("/api/statistics/live?after_id={$cursor}")->assertOk()->json('data');

    expect($delta['events'])->toHaveCount(1)
        ->and($delta['events'][0]['id'])->toBe($second->id)
        ->and($delta['cursor'])->toBe($second->id)
        // A delta is the fast tick: the aggregates are not recomputed for it.
        ->and($delta)->not->toHaveKey('pulse');

    $empty = $this->actingAs($user)->getJson("/api/statistics/live?after_id={$second->id}&full=1")->assertOk()->json('data');

    expect($empty['events'])->toBe([])
        // The cursor must not rewind on an empty delta, or the next poll
        // replays everything the client already drew.
        ->and($empty['cursor'])->toBe($second->id)
        ->and($empty)->toHaveKey('pulse');
});

it('reports a receipt that landed after the row was drawn', function () {
    $user = liveOwner();
    $conversation = liveConversation(liveConnection($user));

    $sent = liveMessage($conversation, SenderType::Outgoing);
    $untouched = liveMessage($conversation, SenderType::Outgoing);

    // The customer read it a moment later. This edits the row rather than
    // creating one, so the keyset delta can never carry it.
    $sent->update(['read_at' => now()]);

    $data = $this->actingAs($user)
        ->getJson("/api/statistics/live?after_id={$untouched->id}&full=1")
        ->assertOk()
        ->json('data');

    expect($data['events'])->toBe([])
        ->and($data['status_updates'])->toBe([['id' => $sent->id, 'status' => 'read']])
        ->and(collect($data['status_updates'])->pluck('id'))->not->toContain($untouched->id);
});

it('hides the stream of connections an agent was not given', function () {
    $owner = liveOwner();
    $mine = liveConnection($owner, 'Minha');
    $theirs = liveConnection($owner, 'Outra');

    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'name' => 'Bruno']);
    $agent->assignRole(Role::findOrCreate('agent', 'web'));
    Role::findOrCreate('agent', 'web')->givePermissionTo(
        Permission::findOrCreate('statistics.tenant.view', 'web')
    );
    $agent->connections()->sync([$mine->id]);

    liveMessage(liveConversation($mine), SenderType::Incoming);
    liveMessage(liveConversation($theirs), SenderType::Incoming);

    $events = $this->actingAs($agent->fresh())->getJson('/api/statistics/live')->assertOk()->json('data.events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['connection']['name'])->toBe('Minha');
});

it('does not let one viewer\'s cached counters answer another\'s question', function () {
    // The aggregates are cached for a few seconds so several open boards share
    // one computation. The key has to carry the connection scope, or the owner
    // warming it would hand an agent counts for inboxes they cannot see.
    $owner = liveOwner();
    $mine = liveConnection($owner, 'Minha');
    $theirs = liveConnection($owner, 'Outra');

    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id, 'name' => 'Bruno']);
    $role = Role::findOrCreate('agent', 'web');
    $role->givePermissionTo(Permission::findOrCreate('statistics.tenant.view', 'web'));
    $agent->assignRole($role);
    $agent->connections()->sync([$mine->id]);

    liveMessage(liveConversation($mine), SenderType::Incoming);
    liveMessage(liveConversation($theirs), SenderType::Incoming);
    liveMessage(liveConversation($theirs), SenderType::Incoming);

    $ownerPulse = $this->actingAs($owner)->getJson('/api/statistics/live')->assertOk()->json('data.pulse');
    $agentPulse = $this->actingAs($agent->fresh())->getJson('/api/statistics/live')->assertOk()->json('data.pulse');

    expect($ownerPulse['inbound_window'])->toBe(3)
        ->and($agentPulse['inbound_window'])->toBe(1);
});

it('reads presence off the heartbeat and names what the agent is on', function () {
    $user = liveOwner();
    $agent = User::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Carla']);
    $agent->forceFill(['last_seen_at' => now()])->save();

    $connection = liveConnection($user);
    $conversation = liveConversation($connection, ['user_id' => $agent->id, 'status' => ConversationStatus::Active]);
    liveMessage($conversation, SenderType::Outgoing, null, ['sent_by_user_id' => $agent->id]);

    $agents = collect($this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data.agents'))
        ->keyBy('name');

    expect($agents['Carla']['presence'])->toBe('active')
        ->and($agents['Carla']['open_conversations'])->toBe(1)
        ->and($agents['Carla']['handling']['contact'])->toBe('João Pereira')
        ->and($agents['Carla']['handling']['connection'])->toBe('Vendas')
        // Never signed in, so no heartbeat — offline, not "unknown".
        ->and($agents['Owner']['presence'])->toBe('offline')
        ->and($agents['Owner']['handling'])->toBeNull();
});

it('withholds the roster from someone without the agent statistics permission', function () {
    $user = liveOwner(['statistics.tenant.view']);

    $data = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data');

    expect($data['agents'])->toBeNull()
        ->and($data['pulse'])->not->toBeNull();
});

it('counts the queue and how long its oldest thread has waited', function () {
    $user = liveOwner();
    $connection = liveConnection($user);

    liveConversation($connection, [
        'status' => ConversationStatus::Pending,
        'last_message_at' => now()->subMinutes(20),
    ]);
    liveConversation($connection, [
        'status' => ConversationStatus::Active,
        'user_id' => $user->id,
        'last_message_at' => now(),
    ]);

    $pulse = $this->actingAs($user)->getJson('/api/statistics/live')->assertOk()->json('data.pulse');

    expect($pulse['waiting'])->toBe(1)
        ->and($pulse['waiting_unassigned'])->toBe(1)
        ->and($pulse['active_conversations'])->toBe(1)
        ->and($pulse['oldest_waiting_seconds'])->toBeGreaterThanOrEqual(1190);
});
