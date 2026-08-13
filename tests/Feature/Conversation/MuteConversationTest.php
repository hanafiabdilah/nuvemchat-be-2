<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function muteTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function muteTestConversation(User $user, ConversationType $type = ConversationType::Private): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    // Agents reach an inbox through connection_user; a fixture that skips
    // the grant describes an account the signup flow cannot produce.
    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => $type === ConversationType::Group ? '120363419920035031@g.us' : '5511999999999',
        'name' => $type === ConversationType::Group ? 'Equipe' : 'Ana',
        'channel' => $connection->channel,
        'is_group' => $type === ConversationType::Group,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => $type,
        'status' => ConversationStatus::Pending,
    ]);
}

test('a conversation can be muted and unmuted', function () {
    Event::fake();
    $user = muteTestUser();
    $conversation = muteTestConversation($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/mute")
        ->assertOk()
        ->assertJsonPath('data.muted', true);

    expect($conversation->fresh()->isMuted())->toBeTrue();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/conversations/{$conversation->id}/mute")
        ->assertOk()
        ->assertJsonPath('data.muted', false);

    expect($conversation->fresh()->isMuted())->toBeFalse()
        ->and($conversation->fresh()->muted_at)->toBeNull();
});

test('muting a group works the same way', function () {
    Event::fake();
    $user = muteTestUser();
    $conversation = muteTestConversation($user, ConversationType::Group);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/mute")
        ->assertOk()
        ->assertJsonPath('data.muted', true);

    expect($conversation->fresh()->isMuted())->toBeTrue();
});

test('muting broadcasts so every open inbox flips at once', function () {
    Event::fake();
    $user = muteTestUser();
    $conversation = muteTestConversation($user);

    $this->actingAs($user, 'sanctum')->postJson("/api/conversations/{$conversation->id}/mute")->assertOk();

    Event::assertDispatched(ConversationUpdated::class, fn ($event) => $event->conversation->id === $conversation->id
        && $event->conversation->isMuted());
});

test('an unassigned pending thread is mutable by any tenant member', function () {
    Event::fake();
    $owner = muteTestUser();
    $conversation = muteTestConversation($owner);

    // A plain agent: not the assignee (there is none), not an owner — exactly
    // the case that must still be able to silence a noisy group.
    //
    // They do hold the connection, which is the boundary mute respects now
    // that channels are per-connection: silencing a thread whose notifications
    // could never reach you is meaningless. What mute still does NOT require
    // is the thread being accessible — i.e. assigned to you.
    $agent = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $agent->connections()->syncWithoutDetaching([$conversation->connection_id]);

    expect($conversation->isAccessibleBy($agent))->toBeFalse();

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/mute")
        ->assertOk();

    expect($conversation->fresh()->isMuted())->toBeTrue();
});

test('a conversation from another tenant cannot be muted', function () {
    Event::fake();
    $user = muteTestUser();
    $stranger = muteTestUser();
    $conversation = muteTestConversation($stranger);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/mute")
        ->assertNotFound();

    expect($conversation->fresh()->isMuted())->toBeFalse();
});

test('mute survives in the conversation list payload', function () {
    Event::fake();
    $user = muteTestUser();
    $conversation = muteTestConversation($user);
    $conversation->update(['muted_at' => now()]);

    // The list skips conversations that have never carried a message.
    $conversation->messages()->create([
        'external_id' => 'MSG-1',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.muted', true);
});
