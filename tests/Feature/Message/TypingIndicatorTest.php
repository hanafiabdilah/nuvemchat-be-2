<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\Widget\WidgetTyping;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\LiveChatSession;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\Contracts\SendsTypingIndicator;
use App\Services\Message\MessageFactory;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function typingConversation(Channel $channel, array $credentials = []): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Conn',
        'status' => ConnectionStatus::Active,
        'credentials' => $credentials,
    ]);

    // Access is the connection_user pivot, not tenant membership — see
    // Conversation::visibleTo().
    $user->connections()->syncWithoutDetaching([$connection->id]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'name' => 'Maria',
        'external_id' => '5511888887777',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511888887777',
        'status' => ConversationStatus::Active,
        'user_id' => $user->id,
    ]);
}

test('every channel with a refresh interval has a handler that can send one', function () {
    foreach (Channel::cases() as $channel) {
        $handler = rescue(fn () => MessageFactory::make($channel), null, report: false);

        if (! $handler) {
            continue;
        }

        expect($handler instanceof SendsTypingIndicator)
            ->toBe($channel->supportsTypingIndicator(), "channel {$channel->value}");
    }
});

test('the refresh interval always leaves room inside the platform timeout', function () {
    // Telegram clears after 5s and Discord after 10s — the two that make a
    // single shared interval impossible.
    expect(Channel::Telegram->typingRefreshSeconds())->toBeLessThan(5)
        ->and(Channel::Discord->typingRefreshSeconds())->toBeLessThan(10)
        ->and(Channel::TikTok->typingRefreshSeconds())->toBeNull()
        ->and(Channel::Email->typingRefreshSeconds())->toBeNull()
        ->and(Channel::Email->supportsTypingIndicator())->toBeFalse();
});

test('WhatsApp Cloud API rides the indicator on the mark-as-read call', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = typingConversation(Channel::WhatsappOfficial, [
        'access_token' => 'tok',
        'phone_number_id' => '1083508778182246',
    ]);

    // Cloud API attaches the indicator to a specific inbound message, so there
    // has to be one to attach it to.
    $conversation->messages()->create([
        'external_id' => 'wamid.INBOUND',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
    ]);

    expect((new MessageService)->sendTyping($conversation))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/1083508778182246/messages')
        && $request['status'] === 'read'
        && $request['message_id'] === 'wamid.INBOUND'
        && $request['typing_indicator'] === ['type' => 'text']);
});

test('WhatsApp Cloud API has no way to withdraw the indicator, so nothing is sent', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = typingConversation(Channel::WhatsappOfficial, [
        'access_token' => 'tok',
        'phone_number_id' => '1083508778182246',
    ]);

    expect((new MessageService)->sendTyping($conversation, false))->toBeFalse();

    Http::assertNothingSent();
});

test('API Way sends composing and, unlike the rest, a real paused', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = typingConversation(Channel::WhatsappApiway, [
        'instance_id' => 'INST-1',
        'token' => 'tok',
    ]);

    (new MessageService)->sendTyping($conversation);
    (new MessageService)->sendTyping($conversation, false);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/chats/send-presence')
        && str_contains($request->url(), 'instanceId=INST-1')
        && $request['phone'] === '5511888887777'
        && $request['presence'] === 'composing'
        && $request['media'] === 'text');

    Http::assertSent(fn ($request) => ($request['presence'] ?? null) === 'paused');
});

test('Discord pokes the channel typing endpoint and never tries to clear it', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $conversation = typingConversation(Channel::Discord, ['token' => 'bot-token']);
    $conversation->update(['external_id' => '1234567890']);

    expect((new MessageService)->sendTyping($conversation))->toBeTrue()
        ->and((new MessageService)->sendTyping($conversation, false))->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/v10/channels/1234567890/typing'
        && $request->hasHeader('Authorization', 'Bot bot-token'));
});

test('Messenger and Instagram send a sender action, and theirs can be turned off', function () {
    Http::fake(['*' => Http::response(['recipient_id' => '1'])]);

    $messenger = typingConversation(Channel::Messenger, ['access_token' => 'page-tok']);
    (new MessageService)->sendTyping($messenger);

    $instagram = typingConversation(Channel::Instagram, ['access_token' => 'ig-tok']);
    (new MessageService)->sendTyping($instagram, false);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/')
        && $request['sender_action'] === 'typing_on'
        && $request['recipient'] === ['id' => '5511888887777']
        // Meta rejects a sender action that travels with anything else.
        && ! isset($request['message'], $request['messaging_type']));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.instagram.com/')
        && $request['sender_action'] === 'typing_off');
});

test('the widget broadcasts its own event instead of calling anyone', function () {
    Event::fake();

    $conversation = typingConversation(Channel::LiveChatWidget);
    LiveChatSession::create([
        'connection_id' => $conversation->connection_id,
        'conversation_id' => $conversation->id,
        'session_token' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    expect((new MessageService)->sendTyping($conversation))->toBeTrue();

    Event::assertDispatched(WidgetTyping::class, function ($event) use ($conversation) {
        $payload = $event->broadcastWith();

        return $event->conversationId === $conversation->id
            && $payload['typing'] === true
            && $payload['agent'] === $conversation->agent->name;
    });
});

test('a channel without an indicator is a silent no-op, not a failure', function () {
    Http::fake();

    $conversation = typingConversation(Channel::TikTok, ['access_token' => 'tok']);

    expect((new MessageService)->sendTyping($conversation))->toBeFalse();

    Http::assertNothingSent();
});

test('a channel refusing the request never reaches the caller', function () {
    Http::fake(['*' => Http::response(['error' => 'nope'], 400)]);

    $conversation = typingConversation(Channel::WhatsappApiway, [
        'instance_id' => 'INST-1',
        'token' => 'tok',
    ]);

    // False, not an exception: the composer calls this blind on a timer.
    expect((new MessageService)->sendTyping($conversation))->toBeFalse();
});

test('the endpoint reports success even where the channel could do nothing', function () {
    Http::fake();

    $conversation = typingConversation(Channel::TikTok, ['access_token' => 'tok']);
    $agent = $conversation->connection->tenant->user;

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing")
        ->assertOk();
});

test('the endpoint refuses a conversation the agent cannot see', function () {
    $conversation = typingConversation(Channel::WhatsappOfficial, ['access_token' => 'tok']);
    $stranger = User::factory()->create();
    Tenant::create(['user_id' => $stranger->id]);

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing")
        ->assertNotFound();

    expect(Message::count())->toBe(0);
});

test('state=paused is what turns the request into a withdrawal', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = typingConversation(Channel::WhatsappApiway, [
        'instance_id' => 'INST-1',
        'token' => 'tok',
    ]);
    $agent = $conversation->connection->tenant->user;

    $this->actingAs($agent, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/typing", ['state' => 'paused'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['presence'] === 'paused');
});
