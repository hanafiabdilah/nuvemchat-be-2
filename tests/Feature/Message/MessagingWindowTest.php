<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\ExpiredWindowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function windowUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

function windowConversation(User $user, Channel $channel = Channel::WhatsappOfficial): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => $channel,
        'name' => 'Atendimento',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'access_token' => 'token',
            'phone_number_id' => '123456',
            'business_account_id' => '654321',
        ],
    ]);

    $contact = Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Active,
        'user_id' => $user->id,
    ]);
}

function inboundMessage(Conversation $conversation, int $hoursAgo): Message
{
    return $conversation->messages()->create([
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now()->subHours($hoursAgo),
    ]);
}

test('a send is refused once the WhatsApp 24-hour window has closed', function () {
    Event::fake();
    Http::fake();
    $user = windowUser();
    $conversation = windowConversation($user);
    inboundMessage($conversation, 25);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'Olá?'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'messaging_window_closed')
        ->assertJsonPath('window.hours', 24);

    // Nothing reached Meta, and no outgoing row was written — the bug this
    // guard exists for is a bubble in the thread nobody ever received.
    Http::assertNothingSent();
    expect($conversation->messages()->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Text)->exists())->toBeFalse();
});

test('an expired window resolves the conversation and leaves an info note', function () {
    Event::fake();
    Http::fake();
    $user = windowUser();
    $conversation = windowConversation($user);
    inboundMessage($conversation, 48);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'Olá?'])
        ->assertStatus(422)
        // The refusal carries the resulting state, so the tab that sent it can
        // close the thread without waiting on the broadcast.
        ->assertJsonPath('conversation_id', $conversation->id)
        ->assertJsonPath('conversation_status', 'resolved');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Resolved);

    $info = $conversation->messages()->where('message_type', MessageType::Info)->first();
    expect($info)->not->toBeNull()
        ->and($info->meta['info']['code'])->toBe(ExpiredWindowResolver::CODE)
        ->and($info->meta['info']['params']['hours'])->toBe(24);

    Event::assertDispatched(MessageReceived::class);
    Event::assertDispatched(ConversationUpdated::class);
});

test('a send inside the window still goes through', function () {
    Event::fake();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
    ]);
    $user = windowUser();
    $conversation = windowConversation($user);
    inboundMessage($conversation, 2);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'Olá!'])
        ->assertOk();

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Active)
        ->and($conversation->messages()->where('message_type', MessageType::Info)->exists())->toBeFalse();
});

test('a thread still waiting for its first inbound message is blocked but stays open', function () {
    Event::fake();
    Http::fake();
    $user = windowUser();
    // A conversation opened by a template: the customer has not written yet, so
    // there is no window to send inside — and none to have expired either.
    $conversation = windowConversation($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'Olá?'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'messaging_window_closed')
        // Still Active: nothing to close, the customer's first reply is what
        // opens the window, and it may yet arrive.
        ->assertJsonPath('conversation_status', 'active');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Active)
        ->and($conversation->messages()->where('message_type', MessageType::Info)->exists())->toBeFalse();
});

test('a channel without a session window is never blocked', function () {
    Event::fake();
    Http::fake();
    $user = windowUser();
    $conversation = windowConversation($user, Channel::WhatsappApiway);
    inboundMessage($conversation, 72);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/send-message", ['message' => 'Olá!']);

    // Whatever the API Way send itself does here, the window guard must not be
    // what answered: the conversation stays live and un-noted.
    expect($response->json('code'))->not->toBe('messaging_window_closed');
    expect($conversation->fresh()->status)->toBe(ConversationStatus::Active)
        ->and($conversation->messages()->where('message_type', MessageType::Info)->exists())->toBeFalse();
});

test('a conversation cannot be started on WhatsApp Official', function () {
    Event::fake();
    Http::fake();
    $user = windowUser();
    $conversation = windowConversation($user);
    // store() only lets an owner or an assigned agent through; assignment is
    // what this test needs so the refusal it asserts is about the channel.
    $conversation->connection->users()->attach($user->id);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/conversations', [
            'contact_id' => $conversation->contact_id,
            'connection_id' => $conversation->connection_id,
            'message' => 'Olá!',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'channel_cannot_start_conversation');

    Http::assertNothingSent();
});

test('the sweep closes expired conversations and leaves fresh ones alone', function () {
    Event::fake();
    $user = windowUser();

    $expired = windowConversation($user);
    inboundMessage($expired, 30);

    $fresh = windowConversation($user);
    inboundMessage($fresh, 3);

    $awaitingFirstReply = windowConversation($user);

    $this->artisan('conversations:close-expired-windows')->assertExitCode(0);

    expect($expired->fresh()->status)->toBe(ConversationStatus::Resolved)
        ->and($fresh->fresh()->status)->toBe(ConversationStatus::Active)
        ->and($awaitingFirstReply->fresh()->status)->toBe(ConversationStatus::Active);
});
