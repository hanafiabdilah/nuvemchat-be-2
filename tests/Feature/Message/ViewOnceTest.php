<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\MessageNodes;
use App\Services\Message\MessageService;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ChannelRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Storage::fake('local');
    Storage::fake('public');
});

function viewOnceConversation(Channel $channel = Channel::WhatsappApiway): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => $channel === Channel::WhatsappApiway
            ? ['instance_id' => 'INST-1', 'token' => 'test-token']
            : ['phone_number_id' => '106540352242922', 'access_token' => 'wa-token', 'business_account_id' => '111'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id, 'channel' => $channel, 'external_id' => '5511999999999',
        'name' => 'Ana', 'username' => '5511999999999',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id,
        'external_id' => '5511999999999', 'status' => ConversationStatus::Active,
    ]);
}

function viewOncePayloads(string $needle): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (ChannelRequest $request) => str_contains($request->url(), $needle))
        ->map(fn (ChannelRequest $request) => $request->data())
        ->values()
        ->all();
}

function viewOnceMeta(Message $message): ?array
{
    return (new MessageResource($message->fresh()->load('conversation.connection')))->toArray(request())['meta'] ?? null;
}

test('an image sent as view once carries the flag to WhatsApp and to the thread', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-1']])]);
    $conversation = viewOnceConversation();

    $message = (new MessageService)->sendImage($conversation, [
        'media_url' => 'https://cdn.example.com/preview.png',
        'message' => 'Olha só',
        'view_once' => true,
    ]);

    expect(viewOncePayloads('send-image')[0]['viewOnce'])->toBeTrue()
        ->and(viewOnceMeta($message)['view_once'])->toBeTrue();
});

test('without the option nothing about view once is sent or shown', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-1']])]);
    $conversation = viewOnceConversation();

    $message = (new MessageService)->sendVideo($conversation, ['media_url' => 'https://cdn.example.com/clip.mp4']);

    expect(viewOncePayloads('send-video')[0])->not->toHaveKey('viewOnce')
        ->and(viewOnceMeta($message)['view_once'] ?? null)->toBeNull();
});

test('a channel that cannot do view once sends the file normally and does not claim otherwise', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
    $conversation = viewOnceConversation(Channel::WhatsappOfficial);

    $message = (new MessageService)->sendImage($conversation, [
        'media_url' => 'https://cdn.example.com/preview.png',
        'view_once' => true,
    ]);

    expect($message)->not->toBeNull()
        ->and(viewOnceMeta($message)['view_once'] ?? null)->toBeNull();
});

test('only an image or a video bubble of a flow can be view once', function () {
    expect(MessageNodes::normalizeItem(['message_type' => 'image', 'attachment_url' => 'https://x.test/a.png', 'view_once' => true])['view_once'])->toBeTrue()
        ->and(MessageNodes::normalizeItem(['message_type' => 'document', 'attachment_url' => 'https://x.test/a.pdf', 'view_once' => true])['view_once'])->toBeFalse()
        ->and(MessageNodes::normalizeItem(['message_type' => 'image', 'attachment_url' => 'https://x.test/a.png'])['view_once'])->toBeFalse();
});

function viewOnceIncoming(array $message, array $extra = [], string $type = 'Message'): array
{
    return [
        'type' => $type,
        'event' => array_merge([
            'Info' => [
                'ID' => 'AC'.uniqid(),
                'Chat' => '118300696653886@lid',
                'Sender' => '118300696653886@lid',
                'SenderAlt' => '5511999999999@s.whatsapp.net',
                'PushName' => 'Ana',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-10-05T11:08:00-03:00',
            ],
            'Message' => $message,
        ], $extra),
    ];
}

test('a view-once picture from a customer is stored as a picture and flagged', function (array $payload) {
    // The file itself is fetched off the queue; this is about the flag.
    Queue::fake([App\Jobs\DownloadInboundMedia::class, App\Jobs\SyncContactPhoto::class]);
    $conversation = viewOnceConversation();
    $conversation->update(['status' => ConversationStatus::Pending]);

    (new WhatsappApiwayHandler)->handle($conversation->connection, $payload);

    $message = Message::where('sender_type', 'incoming')->sole();

    expect($message->message_type)->toBe(MessageType::Image)
        ->and(viewOnceMeta($message)['view_once'])->toBeTrue();
})->with([
    'unwrapped by the core' => [viewOnceIncoming(['imageMessage' => ['caption' => 'olha', 'mimetype' => 'image/jpeg']], ['IsViewOnce' => true])],
    'still wrapped' => [viewOnceIncoming(['viewOnceMessageV2' => ['message' => ['imageMessage' => ['caption' => 'olha', 'mimetype' => 'image/jpeg']]]])],
]);

test('a view-once message WhatsApp kept on the phone still shows up in the thread', function () {
    $conversation = viewOnceConversation();
    $conversation->update(['status' => ConversationStatus::Pending]);

    (new WhatsappApiwayHandler)->handle(
        $conversation->connection,
        viewOnceIncoming([], ['UnavailableType' => 'view_once', 'IsUnavailable' => true], 'UndecryptableMessage'),
    );

    $message = Message::where('sender_type', 'incoming')->sole();

    expect($message->message_type)->toBe(MessageType::Unsupported)
        ->and(viewOnceMeta($message)['view_once'])->toBeTrue();
});
