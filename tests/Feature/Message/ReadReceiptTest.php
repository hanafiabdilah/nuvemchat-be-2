<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\Widget\WidgetMessagesRead;
use App\Jobs\SendReadReceipt;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\LiveChatSession;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailInboxClient;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Message\Contracts\MarksMessagesAsRead;
use App\Services\Message\MessageFactory;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function readReceiptConversation(Channel $channel, array $credentials = []): Conversation
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

function readReceiptInbound(Conversation $conversation, string $externalId, array $meta = []): Message
{
    return $conversation->messages()->create([
        'external_id' => $externalId,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'oi',
        'sent_at' => now(),
        'meta' => $meta ?: null,
    ]);
}

test('every channel that claims a read receipt has a handler that can send one', function () {
    foreach (Channel::cases() as $channel) {
        $handler = rescue(fn () => MessageFactory::make($channel), null, report: false);

        if (! $handler) {
            continue;
        }

        expect($handler instanceof MarksMessagesAsRead)
            ->toBe($channel->supportsReadReceipt(), "channel {$channel->value}");
    }
});

test('the three channels that cannot be told stay out', function () {
    // A Telegram bot has no read API, Discord refuses bots on ack, and TikTok
    // publishes read events without accepting any.
    expect(Channel::Telegram->supportsReadReceipt())->toBeFalse()
        ->and(Channel::Discord->supportsReadReceipt())->toBeFalse()
        ->and(Channel::TikTok->supportsReadReceipt())->toBeFalse();
});

test('opening a thread queues the receipt with exactly the messages that turned read', function () {
    Queue::fake();

    $conversation = readReceiptConversation(Channel::WhatsappOfficial, [
        'access_token' => 'tok',
        'phone_number_id' => '108350877',
    ]);

    $alreadyRead = readReceiptInbound($conversation, 'wamid.OLD');
    $alreadyRead->update(['read_at' => now()->subDay()]);

    $first = readReceiptInbound($conversation, 'wamid.ONE');
    $second = readReceiptInbound($conversation, 'wamid.TWO');

    // An outgoing message is ours; reading our own reply is not a receipt.
    $conversation->messages()->create([
        'external_id' => 'wamid.MINE',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'ola',
        'sent_at' => now(),
    ]);

    $this->actingAs($conversation->agent, 'sanctum')
        ->getJson("/api/conversations/{$conversation->id}/read")
        ->assertOk();

    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->not->toBeNull();

    Queue::assertPushed(SendReadReceipt::class, fn ($job) => $job->messageIds === [$first->id, $second->id]);
});

test('nothing is queued when the thread had nothing unread, or the channel cannot hear it', function () {
    Queue::fake();

    $read = readReceiptConversation(Channel::WhatsappOfficial, ['access_token' => 'tok']);
    readReceiptInbound($read, 'wamid.OLD')->update(['read_at' => now()]);

    $this->actingAs($read->agent, 'sanctum')
        ->getJson("/api/conversations/{$read->id}/read")
        ->assertOk();

    $telegram = readReceiptConversation(Channel::Telegram, ['token' => 'bot-tok']);
    readReceiptInbound($telegram, '4242');

    $this->actingAs($telegram->agent, 'sanctum')
        ->getJson("/api/conversations/{$telegram->id}/read")
        ->assertOk();

    // The badge still cleared; only the outbound half was skipped.
    expect($telegram->messages()->whereNull('read_at')->count())->toBe(0);

    Queue::assertNotPushed(SendReadReceipt::class);
});

test('WhatsApp Cloud API is told about the newest message, without a typing indicator', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = readReceiptConversation(Channel::WhatsappOfficial, [
        'access_token' => 'tok',
        'phone_number_id' => '1083508778182246',
    ]);

    $first = readReceiptInbound($conversation, 'wamid.ONE');
    $second = readReceiptInbound($conversation, 'wamid.TWO');

    expect((new MessageService)->markAsRead($conversation, collect([$first, $second])))->toBeTrue();

    // One call, keyed to the newest: Cloud API marks everything before it read.
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/1083508778182246/messages')
        && $request['status'] === 'read'
        && $request['message_id'] === 'wamid.TWO'
        && ! isset($request['typing_indicator']));
});

test('API Way names the chat and the message to the core', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = readReceiptConversation(Channel::WhatsappApiway, [
        'instance_id' => 'INST-1',
        'token' => 'tok',
    ]);

    $message = readReceiptInbound($conversation, '3EB0C767D26B8C1F4A2B');

    expect((new MessageService)->markAsRead($conversation, collect([$message])))->toBeTrue();

    // POST /v1/message/read-message — the *message* group, not the chats one
    // that owns send-presence. A private chat carries no senderPhone.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/message/read-message')
        && str_contains($request->url(), 'instanceId=INST-1')
        && $request['phone'] === '5511888887777'
        && $request['messageId'] === '3EB0C767D26B8C1F4A2B'
        && ! isset($request['senderPhone']));
});

test('API Way names the member too when the thread is a group', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    $conversation = readReceiptConversation(Channel::WhatsappApiway, [
        'instance_id' => 'INST-1',
        'token' => 'tok',
    ]);
    $conversation->update([
        'type' => ConversationType::Group,
        'external_id' => '120363419920035031@g.us',
    ]);

    $sender = Contact::create([
        'tenant_id' => $conversation->connection->tenant_id,
        'name' => 'Joao',
        'external_id' => '5511777776666',
    ]);

    $message = readReceiptInbound($conversation, '3EB0C767D26B8C1F4A2B');
    $message->update(['contact_id' => $sender->id]);

    expect((new MessageService)->markAsRead($conversation, collect([$message->fresh()])))->toBeTrue();

    // In a group the chat is the @g.us JID, so the receipt has to say whose
    // message was read as well.
    Http::assertSent(fn ($request) => $request['phone'] === '120363419920035031@g.us'
        && $request['senderPhone'] === '5511777776666');
});

test('a core that refuses the receipt is a logged false, not an exception', function () {
    Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

    $conversation = readReceiptConversation(Channel::WhatsappApiway, [
        'instance_id' => 'INST-1',
        'token' => 'tok',
    ]);

    $message = readReceiptInbound($conversation, '3EB0C767D26B8C1F4A2B');

    expect((new MessageService)->markAsRead($conversation, collect([$message])))->toBeFalse();
});

test('Messenger and Instagram send mark_seen and nothing else', function () {
    Http::fake(['*' => Http::response(['recipient_id' => '1'])]);

    $messenger = readReceiptConversation(Channel::Messenger, ['access_token' => 'page-tok']);
    (new MessageService)->markAsRead($messenger, collect([readReceiptInbound($messenger, 'mid.ONE')]));

    $instagram = readReceiptConversation(Channel::Instagram, ['access_token' => 'ig-tok']);
    (new MessageService)->markAsRead($instagram, collect([readReceiptInbound($instagram, 'mid.TWO')]));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/')
        && $request['sender_action'] === 'mark_seen'
        && $request['recipient'] === ['id' => '5511888887777']
        // Meta rejects a sender action that travels with anything else.
        && ! isset($request['message'], $request['messaging_type']));

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.instagram.com/')
        && $request['sender_action'] === 'mark_seen');
});

test('the widget broadcasts the ids so the visitor watches their own ticks turn', function () {
    Event::fake();

    $conversation = readReceiptConversation(Channel::LiveChatWidget);
    LiveChatSession::create([
        'connection_id' => $conversation->connection_id,
        'conversation_id' => $conversation->id,
        'session_token' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $message = readReceiptInbound($conversation, 'widget-1');

    expect((new MessageService)->markAsRead($conversation, collect([$message])))->toBeTrue();

    Event::assertDispatched(WidgetMessagesRead::class, function ($event) use ($conversation, $message) {
        $payload = $event->broadcastWith();

        return $event->conversationId === $conversation->id
            && $payload['message_ids'] === [$message->id];
    });
});

test('e-mail flags the stored UIDs on the mailbox and skips messages ingested without one', function () {
    $client = new class implements EmailInboxClient
    {
        public array $seen = [];

        public function uidsAfter(int $uid): array
        {
            return [];
        }

        public function uidsWithin(?DateTimeInterface $since, ?int $beforeUid): array
        {
            return [];
        }

        public function fetch(array $uids, ?int $maxMessageBytes = null): iterable
        {
            return [];
        }

        public function markSeen(array $uids): int
        {
            $this->seen = $uids;

            return count($uids);
        }

        public function disconnect(): void {}
    };

    app()->bind(EmailInboxClientFactory::class, fn () => new class($client) implements EmailInboxClientFactory
    {
        public function __construct(private readonly EmailInboxClient $client) {}

        public function make(Connection $connection): EmailInboxClient
        {
            return $this->client;
        }
    });

    $conversation = readReceiptConversation(Channel::Email, ['email' => 'suporte@empresa.com']);

    $withUid = readReceiptInbound($conversation, '<a@empresa.com>', ['email' => ['uid' => 4210]]);
    // Ingested before the UID was persisted: skipped rather than guessed at.
    readReceiptInbound($conversation, '<b@empresa.com>', ['email' => ['subject' => 'sem uid']]);

    expect((new MessageService)->markAsRead($conversation, $conversation->messages()->get()))->toBeTrue()
        ->and($client->seen)->toBe([4210])
        ->and($withUid->meta['email']['uid'])->toBe(4210);
});

test('a channel that cannot be told is a silent false, not a failure', function () {
    Http::fake();

    $conversation = readReceiptConversation(Channel::Telegram, ['token' => 'bot-tok']);

    expect((new MessageService)->markAsRead($conversation, collect([readReceiptInbound($conversation, '77')])))->toBeFalse();

    Http::assertNothingSent();
});
