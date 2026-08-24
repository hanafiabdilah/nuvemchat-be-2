<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\LastAgentRouter;
use App\Services\Webhook\Handlers\Chat\TelegramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const RTLA_CHAT_ID = 771001;

function rtlaOwner(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function rtlaConnection(User $owner, array $overrides = []): Connection
{
    return Connection::create(array_merge([
        'tenant_id' => $owner->tenant_id,
        'channel' => Channel::Telegram,
        'name' => 'Suporte',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'test-token'],
        'return_to_last_agent' => true,
        'return_to_last_agent_minutes' => 15,
    ], $overrides));
}

/** An agent of the tenant holding this connection, online unless told otherwise. */
function rtlaAgent(Connection $connection, ?Carbon\CarbonInterface $lastSeen = null): User
{
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);
    $agent->forceFill(['last_seen_at' => $lastSeen ?? now()])->save();

    return $agent->fresh();
}

function rtlaContact(Connection $connection): Contact
{
    return Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => (string) RTLA_CHAT_ID,
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);
}

/** A conversation this contact already had, closed `$closedMinutesAgo` ago. */
function rtlaPreviousConversation(
    Connection $connection,
    Contact $contact,
    ?User $agent,
    int $closedMinutesAgo = 2,
): Conversation {
    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $agent?->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Resolved,
        'resolved_at' => now()->subMinutes($closedMinutesAgo),
        'resolved_by_user_id' => $agent?->id,
    ]);
}

/** The thread the inbound message is about to land in. */
function rtlaNewConversation(Connection $connection, Contact $contact): Conversation
{
    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Pending,
    ]);
}

function rtlaTelegramMessage(array $overrides = []): array
{
    return [
        'update_id' => 900000101,
        'message' => array_merge([
            'message_id' => 41,
            'from' => ['id' => RTLA_CHAT_ID, 'is_bot' => false, 'first_name' => 'Ana', 'username' => 'ana'],
            'chat' => ['id' => RTLA_CHAT_ID, 'first_name' => 'Ana', 'type' => 'private'],
            'date' => 1754500000,
            'text' => 'Esqueci de perguntar uma coisa',
        ], $overrides),
    ];
}

beforeEach(function () {
    Event::fake();
    Http::fake(); // profile photos / outbound calls stay offline
});

test('a contact who comes back inside the tolerance is handed straight to their last agent', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeTrue();

    $conversation->refresh();

    expect($conversation->user_id)->toBe($agent->id)
        ->and($conversation->status)->toBe(ConversationStatus::Active)
        ->and($conversation->needs_human)->toBeFalse();
});

test('the thread carries a note naming the agent it was returned to', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $conversation = rtlaNewConversation($connection, $contact);
    LastAgentRouter::route($conversation);

    $note = Message::where('conversation_id', $conversation->id)->first();

    expect($note->message_type)->toBe(MessageType::Info)
        // Outgoing keeps platform notes out of the unread badge.
        ->and($note->sender_type)->toBe(SenderType::Outgoing)
        ->and($note->meta['info']['code'])->toBe(LastAgentRouter::INFO_RETURNED)
        ->and($note->meta['info']['params']['agent'])->toBe($agent->name);
});

test('a contact who comes back after the tolerance goes through the normal queue', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner, ['return_to_last_agent_minutes' => 15]);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent, closedMinutesAgo: 16);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
    expect($conversation->fresh()->user_id)->toBeNull()
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Pending);
});

test('an agent who is not online is not given the conversation', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection, lastSeen: now()->subMinutes(30));
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
    expect($conversation->fresh()->user_id)->toBeNull();
});

test('an agent who has never been seen counts as offline', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection);
    $agent->forceFill(['last_seen_at' => null])->save();
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent->fresh());

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
});

test('an agent whose access to the connection was revoked is skipped', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $agent->connections()->detach($connection->id);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
});

test('nothing happens while the switch is off', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner, ['return_to_last_agent' => false]);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
    expect(Message::count())->toBe(0);
});

test('a contact whose last visit was answered only by the bot has nobody to return to', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, agent: null);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
});

test('an older served visit is used when the most recent one was bot-only', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent, closedMinutesAgo: 5);
    rtlaPreviousConversation($connection, $contact, agent: null, closedMinutesAgo: 1);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeTrue();
    expect($conversation->fresh()->user_id)->toBe($agent->id);
});

test('group threads are never routed this way', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $conversation = rtlaNewConversation($connection, $contact);
    $conversation->forceFill(['type' => ConversationType::Group])->save();

    expect(LastAgentRouter::route($conversation))->toBeFalse();
});

test('e-mail is a shared inbox and has no last agent to return to', function () {
    $owner = rtlaOwner();
    $connection = rtlaConnection($owner, ['channel' => Channel::Email]);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    $conversation = rtlaNewConversation($connection, $contact);

    expect(LastAgentRouter::route($conversation))->toBeFalse();
});

test('an inbound message reopens with the last agent instead of starting the flow', function () {
    $owner = rtlaOwner();

    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Menu']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $connection = rtlaConnection($owner, ['flow_id' => $flow->id]);
    $agent = rtlaAgent($connection);
    $contact = rtlaContact($connection);
    rtlaPreviousConversation($connection, $contact, $agent);

    (new TelegramHandler)->handle($connection, rtlaTelegramMessage());

    $conversation = Conversation::where('status', ConversationStatus::Active)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->user_id)->toBe($agent->id)
        // The point of the feature: the chatbot never greets someone it was
        // just talking to.
        ->and(FlowState::count())->toBe(0);
});

test('the setting is saved and read back on the connection', function () {
    $owner = rtlaOwner();
    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo(Permission::findOrCreate('connections.update', 'web'));
    $owner->assignRole($role);

    $connection = rtlaConnection($owner, [
        'return_to_last_agent' => false,
        'return_to_last_agent_minutes' => 15,
    ]);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}", [
            'name' => $connection->name,
            'color' => '#22c55e',
            'return_to_last_agent' => true,
            'return_to_last_agent_minutes' => 45,
        ])
        ->assertOk()
        ->assertJsonPath('data.return_to_last_agent.enabled', true)
        ->assertJsonPath('data.return_to_last_agent.tolerance_minutes', 45);

    expect($connection->fresh()->return_to_last_agent)->toBeTrue()
        ->and($connection->fresh()->return_to_last_agent_minutes)->toBe(45);
});

test('a client that never sends the setting does not switch it off', function () {
    $owner = rtlaOwner();
    $role = Role::findOrCreate('owner', 'web');
    $role->givePermissionTo(Permission::findOrCreate('connections.update', 'web'));
    $owner->assignRole($role);

    $connection = rtlaConnection($owner);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/connections/{$connection->id}", ['name' => 'Renomeado'])
        ->assertOk();

    expect($connection->fresh()->return_to_last_agent)->toBeTrue();
});

test('the heartbeat marks the agent online', function () {
    $owner = rtlaOwner();
    $owner->forceFill(['last_seen_at' => null])->save();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/user/heartbeat')
        ->assertNoContent();

    expect($owner->fresh()->isOnline())->toBeTrue();
});

test('an inbound message from a stranger still starts the flow', function () {
    $owner = rtlaOwner();

    $flow = Flow::create(['tenant_id' => $owner->tenant_id, 'name' => 'Menu']);
    $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);

    $connection = rtlaConnection($owner, ['flow_id' => $flow->id]);
    rtlaAgent($connection);

    (new TelegramHandler)->handle($connection, rtlaTelegramMessage());

    $conversation = Conversation::first();

    expect($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->user_id)->toBeNull()
        ->and(FlowState::count())->toBe(1);
});
