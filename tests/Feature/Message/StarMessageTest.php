<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\MessageUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function starTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function starTestConnection(User $user): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    // Agents reach an inbox through connection_user; a fixture that skips the
    // grant describes an account the signup flow cannot produce.
    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function starTestMessage(
    Connection $connection,
    ConversationStatus $status = ConversationStatus::Active,
    string $body = 'Guarde este número: 1234',
): Message {
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => $status,
    ]);

    return $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);
}

test('a message can be starred and unstarred', function () {
    Event::fake();
    $user = starTestUser();
    $message = starTestMessage(starTestConnection($user));
    $conversationId = $message->conversation_id;

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversationId}/messages/{$message->id}/star")
        ->assertOk()
        ->assertJsonPath('data.id', $message->id);

    expect($message->fresh()->starred_at)->not->toBeNull();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/conversations/{$conversationId}/messages/{$message->id}/star")
        ->assertOk();

    expect($message->fresh()->starred_at)->toBeNull();
});

test('starring broadcasts so every open panel shows the star', function () {
    Event::fake();
    $user = starTestUser();
    $message = starTestMessage(starTestConnection($user));

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$message->conversation_id}/messages/{$message->id}/star")
        ->assertOk();

    Event::assertDispatched(MessageUpdated::class, fn ($event) => $event->message->id === $message->id
        && $event->message->starred_at !== null);
});

test('re-starring keeps the original timestamp and raises no event', function () {
    Event::fake();
    $user = starTestUser();
    $message = starTestMessage(starTestConnection($user));
    $message->update(['starred_at' => now()->subDay()]);
    // `timestamp` cast, like every other time column on messages: unix seconds.
    $starredAt = $message->fresh()->starred_at;

    // The list is ordered by starred_at: a second click from another tab must
    // not quietly reorder it under everyone.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$message->conversation_id}/messages/{$message->id}/star")
        ->assertOk();

    expect($message->fresh()->starred_at)->toBe($starredAt);
    Event::assertNotDispatched(MessageUpdated::class);
});

test('the list returns starred messages newest first, with their thread', function () {
    Event::fake();
    $user = starTestUser();
    $connection = starTestConnection($user);

    $older = starTestMessage($connection, body: 'primeiro');
    $older->update(['starred_at' => now()->subDay()]);
    $newer = starTestMessage($connection, body: 'segundo');
    $newer->update(['starred_at' => now()]);
    starTestMessage($connection, body: 'sem estrela');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/starred-messages')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    $response->assertJsonPath('data.0.message.id', $newer->id)
        ->assertJsonPath('data.0.message.body', 'segundo')
        ->assertJsonPath('data.0.conversation.id', $newer->conversation_id)
        ->assertJsonPath('data.1.message.id', $older->id);
});

test('a thread the agent cannot reach is neither listed nor starrable', function () {
    Event::fake();
    $owner = starTestUser();
    $message = starTestMessage(starTestConnection($owner));

    // Same tenant, but never granted this connection — the boundary every
    // message read-path enforces.
    $stranger = User::factory()->create(['tenant_id' => $owner->tenant_id]);

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/conversations/{$message->conversation_id}/messages/{$message->id}/star")
        ->assertNotFound();

    $message->update(['starred_at' => now()]);

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/starred-messages')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('an unassigned pending thread is starrable by any member holding the connection', function () {
    Event::fake();
    $owner = starTestUser();
    $connection = starTestConnection($owner);
    $message = starTestMessage($connection, ConversationStatus::Pending);

    // Not the assignee (there is none), not an owner — starring marks what is
    // already on screen, so it does not wait for the thread to be accepted.
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$connection->id]);

    expect($message->conversation->isAccessibleBy($agent))->toBeFalse();

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$message->conversation_id}/messages/{$message->id}/star")
        ->assertOk();

    expect($message->fresh()->starred_at)->not->toBeNull();
});
