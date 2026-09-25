<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Events\ConversationTakenOver;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Conversation\ConversationReopen;
use App\Services\Webhook\Handlers\Chat\TelegramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const REOPEN_CHAT_ID = 881002;

function reopenOwner(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function reopenConnection(User $owner, array $overrides = []): Connection
{
    return Connection::create(array_merge([
        'tenant_id' => $owner->tenant_id,
        'channel' => Channel::Telegram,
        'name' => 'Suporte',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'test-token'],
        'return_to_last_agent' => true,
        'return_to_last_agent_minutes' => 10,
    ], $overrides));
}

/** A plain agent of the same tenant, holding the connection. */
function reopenAgent(Connection $connection): User
{
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    return $agent->fresh();
}

function reopenContact(Connection $connection): Contact
{
    return Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => (string) REOPEN_CHAT_ID,
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);
}

function reopenResolvedConversation(
    Connection $connection,
    Contact $contact,
    ?User $agent,
    int $closedMinutesAgo = 2,
    array $overrides = [],
): Conversation {
    return Conversation::create(array_merge([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'user_id' => $agent?->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Resolved,
        'resolved_at' => now()->subMinutes($closedMinutesAgo),
        'resolved_by_user_id' => $agent?->id,
        'last_message_at' => now()->subMinutes($closedMinutesAgo),
    ], $overrides));
}

/** The info notes a thread carries, oldest first, by code. */
function reopenNoteCodes(Conversation $conversation): array
{
    return Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->orderBy('id')
        ->get()
        ->map(fn (Message $note) => $note->meta['info']['code'] ?? null)
        ->all();
}

beforeEach(function () {
    Event::fake();
    Http::fake();
});

test('the agent who closed a conversation can open it again inside the tolerance', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.agent.id', $agent->id);

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Active)
        ->and((int) $conversation->user_id)->toBe((int) $agent->id)
        ->and($conversation->needs_human)->toBeFalsy()
        // Cleared: a thread that is open again was not resolved, and leaving
        // the stamp behind would have statistics count an active conversation
        // as closed.
        ->and($conversation->resolved_at)->toBeNull()
        ->and($conversation->resolved_by_user_id)->toBeNull();
});

test('reopening leaves a note in the thread', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertOk();

    // Exactly one: the automatic "resolved → active" note is suppressed because
    // this one names the person, which the transition only implies.
    expect(reopenNoteCodes($conversation))->toBe([ConversationReopen::INFO_REOPENED]);

    $note = Message::where('conversation_id', $conversation->id)->first();

    expect($note->meta['info']['params']['by'])->toBe($agent->name);
});

test('another agent reopening it also takes it over, and the thread says both', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $holder = reopenAgent($connection);
    $taker = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $holder);

    $this->actingAs($taker, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertOk()
        ->assertJsonPath('data.agent.id', $taker->id);

    expect((int) $conversation->refresh()->user_id)->toBe((int) $taker->id)
        ->and(reopenNoteCodes($conversation))->toBe([
            ConversationReopen::INFO_REOPENED,
            ConversationController::INFO_TAKEN_OVER,
        ]);

    $handOver = Message::where('conversation_id', $conversation->id)
        ->where('message_type', MessageType::Info)
        ->orderByDesc('id')
        ->first();

    expect($handOver->meta['info']['params'])->toBe(['from' => $holder->name, 'to' => $taker->name]);

    // The agent who lost the thread is the one who has to hear about it.
    Event::assertDispatched(ConversationTakenOver::class);
});

test('reopening a thread nobody was holding writes only the reopen note', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, null);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertOk();

    expect(reopenNoteCodes($conversation))->toBe([ConversationReopen::INFO_REOPENED]);

    Event::assertNotDispatched(ConversationTakenOver::class);
});

test('past the tolerance the conversation stays closed', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner, ['return_to_last_agent_minutes' => 10]);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent, closedMinutesAgo: 11);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertStatus(422)
        ->assertJsonPath('code', ConversationReopen::BLOCKED_EXPIRED);

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Resolved)
        ->and(Message::where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('a connection that does not return to the last agent does not reopen either', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner, ['return_to_last_agent' => false]);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertStatus(422)
        ->assertJsonPath('code', ConversationReopen::BLOCKED_DISABLED);

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Resolved);
});

test('a conversation that is not resolved cannot be reopened', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent, overrides: [
        'status' => ConversationStatus::Active,
        'resolved_at' => null,
    ]);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertStatus(409)
        ->assertJsonPath('code', ConversationReopen::BLOCKED_NOT_RESOLVED);
});

test('e-mail has no assignee to return a thread to', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner, ['channel' => Channel::Email]);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertStatus(422)
        ->assertJsonPath('code', ConversationReopen::BLOCKED_CHANNEL);
});

test('a group is never reopened this way', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent, overrides: [
        'type' => ConversationType::Group,
    ]);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertStatus(422)
        ->assertJsonPath('code', ConversationReopen::BLOCKED_CHANNEL);
});

test('a contact who already has a live thread is not given a second one', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $closed = reopenResolvedConversation($connection, $contact, $agent);

    // What actually happens in production: the customer wrote back and the
    // routing opened a thread while the agent was still looking at the old one.
    $live = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Pending,
    ]);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$closed->id}/reopen")
        ->assertStatus(409)
        ->assertJsonPath('code', ConversationReopen::BLOCKED_ALREADY_OPEN)
        // Where the customer actually is, so the dashboard can send the agent
        // there instead of leaving them to hunt for it.
        ->assertJsonPath('conversation_id', $live->id);

    expect($closed->refresh()->status)->toBe(ConversationStatus::Resolved);
});

test('a message arriving after a reopen lands in the reopened thread', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertOk();

    (new TelegramHandler)->handle($connection, [
        'update_id' => 900000202,
        'message' => [
            'message_id' => 77,
            'from' => ['id' => REOPEN_CHAT_ID, 'is_bot' => false, 'first_name' => 'Ana'],
            'chat' => ['id' => REOPEN_CHAT_ID, 'first_name' => 'Ana', 'type' => 'private'],
            'date' => 1754500000,
            'text' => 'Voltei',
        ],
    ]);

    // One contact, one open inbox: the handler found the reopened thread
    // instead of forking a second one.
    expect(Conversation::where('contact_id', $contact->id)->count())->toBe(1)
        ->and(Message::where('conversation_id', $conversation->id)->where('body', 'Voltei')->exists())->toBeTrue();
});

test('an agent without access to the connection cannot reopen its threads', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $stranger = User::factory()->create(['tenant_id' => $connection->tenant_id]);

    $this->actingAs($stranger->fresh(), 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertNotFound();
});

test('a thread closed before resolved_at existed still has a window', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);

    // The column was never backfilled, so old rows carry nothing but the last
    // message — the same fallback the automatic routing uses.
    $conversation = reopenResolvedConversation($connection, $contact, $agent, overrides: [
        'resolved_at' => null,
        'resolved_by_user_id' => null,
        'last_message_at' => now()->subMinutes(3),
    ]);

    expect(ConversationReopen::check($conversation)->allowed)->toBeTrue();

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/reopen")
        ->assertOk();
});

test('the closing time reaches the dashboard so it can draw the window', function () {
    $owner = reopenOwner();
    $connection = reopenConnection($owner);
    $agent = reopenAgent($connection);
    $contact = reopenContact($connection);
    $conversation = reopenResolvedConversation($connection, $contact, $agent);

    $this->actingAs($agent, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.resolved_at', $conversation->resolved_at->timestamp);
});
