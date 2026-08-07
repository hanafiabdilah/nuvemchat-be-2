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
use App\Services\Webhook\Handlers\Chat\DiscordHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const DC_BOT_ID = '111222333444555666';
const DC_USER_ID = '777888999000111222';
const DC_DM_CHANNEL = '555666777888999000';

function discordInboundConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Discord,
        'name' => 'Discord',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'bot-token', 'bot_user_id' => DC_BOT_ID],
    ]);
}

function discordMessageCreate(array $overrides = []): array
{
    return [
        't' => 'MESSAGE_CREATE',
        'd' => array_merge([
            'id' => '1000000000000000001',
            'channel_id' => DC_DM_CHANNEL,
            'content' => 'Olá!',
            'timestamp' => '2026-08-07T12:00:00.000000+00:00',
            'attachments' => [],
            'author' => [
                'id' => DC_USER_ID,
                'username' => 'joao',
                'global_name' => 'João',
                'avatar' => null,
            ],
        ], $overrides),
    ];
}

beforeEach(function () {
    Http::fake(); // avatar/media downloads stay offline
});

test('an inbound DM creates contact, conversation and message', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate());

    $message = Message::first();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->name)->toBe('João')
        ->and(Contact::first()->username)->toBe('joao')
        ->and(Contact::first()->external_id)->toBe(DC_USER_ID)
        ->and(Conversation::first()->external_id)->toBe(DC_DM_CHANNEL)
        ->and($message->body)->toBe('Olá!')
        ->and($message->sender_type)->toBe(SenderType::Incoming)
        ->and($message->message_type)->toBe(MessageType::Text);
});

test('messages from other bots are ignored', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'author' => ['id' => '424242', 'username' => 'other-bot', 'bot' => true],
    ]));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('guild messages are never ingested', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'guild_id' => '123123123',
    ]));

    expect(Message::count())->toBe(0);
});

test('a bot echo attaches as outgoing to an existing conversation and is not duplicated', function () {
    Event::fake();
    $connection = discordInboundConnection();

    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => DC_USER_ID,
        'name' => 'João',
        'channel' => Channel::Discord,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => DC_DM_CHANNEL,
        'status' => ConversationStatus::Active,
    ]);

    // Echo of a brand-new message (e.g. sent from the Discord app itself).
    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'id' => '1000000000000000002',
        'content' => 'Respondido pelo app',
        'author' => ['id' => DC_BOT_ID, 'username' => 'pingly-bot', 'bot' => true],
    ]));

    expect(Message::count())->toBe(1)
        ->and(Message::first()->sender_type)->toBe(SenderType::Outgoing);

    // Echo of a message the send handler already stored → deduped.
    $conversation->messages()->create([
        'external_id' => '1000000000000000003',
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'id' => '1000000000000000003',
        'content' => 'Oi',
        'author' => ['id' => DC_BOT_ID, 'username' => 'pingly-bot', 'bot' => true],
    ]));

    expect(Message::count())->toBe(2);
});

test('a bot echo without an existing conversation is dropped', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'author' => ['id' => DC_BOT_ID, 'username' => 'pingly-bot', 'bot' => true],
    ]));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('an image attachment is typed, downloaded and stored', function () {
    Event::fake();
    Http::fake(['cdn.discordapp.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/png'])]);

    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'content' => '',
        'attachments' => [[
            'id' => '1',
            'filename' => 'foto.png',
            'content_type' => 'image/png',
            'url' => 'https://cdn.discordapp.com/attachments/555/1/foto.png',
            'size' => 9,
        ]],
    ]));

    $message = Message::first();

    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->attachment)->not->toBeNull();
});

test('MESSAGE_UPDATE edits the stored body', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate());

    (new DiscordHandler)->handle($connection, [
        't' => 'MESSAGE_UPDATE',
        'd' => [
            'id' => '1000000000000000001',
            'channel_id' => DC_DM_CHANNEL,
            'content' => 'Olá! (editado)',
            'edited_timestamp' => '2026-08-07T12:05:00.000000+00:00',
        ],
    ]);

    $message = Message::first();

    expect($message->body)->toBe('Olá! (editado)')
        ->and($message->edited_at)->not->toBeNull();
});

test('MESSAGE_DELETE marks the message as unsent', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate());

    (new DiscordHandler)->handle($connection, [
        't' => 'MESSAGE_DELETE',
        'd' => ['id' => '1000000000000000001', 'channel_id' => DC_DM_CHANNEL],
    ]);

    expect(Message::first()->unsend_at)->not->toBeNull();
});

test('a reply maps referenced_message to replied_message_id', function () {
    Event::fake();
    $connection = discordInboundConnection();

    (new DiscordHandler)->handle($connection, discordMessageCreate());

    (new DiscordHandler)->handle($connection, discordMessageCreate([
        'id' => '1000000000000000005',
        'content' => 'balasan',
        'referenced_message' => ['id' => '1000000000000000001'],
    ]));

    $reply = Message::where('external_id', '1000000000000000005')->first();
    $original = Message::where('external_id', '1000000000000000001')->first();

    expect($reply->replied_message_id)->toBe($original->id);
});
