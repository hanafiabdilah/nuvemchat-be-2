<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\TelegramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const TG_GROUP_ID = -100987654321;
const TG_ALICE_ID = 501001;
const TG_BOB_ID = 501002;

function telegramGroupConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Telegram,
        'name' => 'Telegram',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'test-token'],
    ]);
}

function telegramGroupMessage(array $overrides = []): array
{
    return [
        'update_id' => 900000001,
        'message' => array_merge([
            'message_id' => 100,
            'from' => ['id' => TG_ALICE_ID, 'is_bot' => false, 'first_name' => 'Alice', 'username' => 'alice'],
            'chat' => ['id' => TG_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'],
            'date' => 1754500000,
            'text' => 'Olá do grupo!',
        ], $overrides),
    ];
}

beforeEach(function () {
    Http::fake(); // profile photo / media downloads stay offline
});

test('a group message creates a group contact, a group conversation and a message with its sender', function () {
    Event::fake();
    $connection = telegramGroupConnection();

    (new TelegramHandler)->handle($connection, telegramGroupMessage());

    $conversation = Conversation::first();
    $message = Message::first();
    $group = Contact::where('is_group', true)->first();
    $alice = Contact::where('external_id', (string) TG_ALICE_ID)->first();

    expect($conversation->type)->toBe(ConversationType::Group)
        ->and($conversation->external_id)->toBe((string) TG_GROUP_ID)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($group)->not->toBeNull()
        ->and($group->name)->toBe('Time de Suporte')
        ->and($conversation->contact_id)->toBe($group->id)
        ->and($alice->is_group)->toBeFalse()
        ->and($message->body)->toBe('Olá do grupo!')
        ->and($message->sender_type)->toBe(SenderType::Incoming)
        ->and($message->contact_id)->toBe($alice->id)
        ->and($conversation->participants()->pluck('contacts.id')->all())->toBe([$alice->id]);
});

test('messages from different members land in the same conversation with per-message senders', function () {
    Event::fake();
    $connection = telegramGroupConnection();
    $handler = new TelegramHandler;

    $handler->handle($connection, telegramGroupMessage());
    $handler->handle($connection, telegramGroupMessage([
        'message_id' => 101,
        'from' => ['id' => TG_BOB_ID, 'is_bot' => false, 'first_name' => 'Bob'],
        'text' => 'Oi!',
    ]));

    $conversation = Conversation::first();

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and($conversation->participants()->count())->toBe(2)
        ->and(Message::orderBy('external_id')->pluck('contact_id')->unique()->count())->toBe(2);
});

test('a resolved group conversation reopens as a fresh conversation on the next message', function () {
    Event::fake();
    $connection = telegramGroupConnection();
    $handler = new TelegramHandler;

    $handler->handle($connection, telegramGroupMessage());
    Conversation::first()->update(['status' => ConversationStatus::Resolved]);

    $handler->handle($connection, telegramGroupMessage(['message_id' => 102, 'text' => 'De novo']));

    expect(Conversation::count())->toBe(2)
        ->and(Contact::where('is_group', true)->count())->toBe(1)
        ->and(Conversation::orderByDesc('id')->first()->status)->toBe(ConversationStatus::Pending);
});

test('a group title change renames the group contact without creating a message', function () {
    Event::fake();
    $connection = telegramGroupConnection();
    $handler = new TelegramHandler;

    $handler->handle($connection, telegramGroupMessage());
    $handler->handle($connection, telegramGroupMessage([
        'message_id' => 103,
        'text' => null,
        'new_chat_title' => 'Suporte N2',
        'chat' => ['id' => TG_GROUP_ID, 'title' => 'Suporte N2', 'type' => 'supergroup'],
    ]));

    expect(Message::count())->toBe(1)
        ->and(Contact::where('is_group', true)->first()->name)->toBe('Suporte N2');
});

test('a my_chat_member update (bot added to a group) is acknowledged without throwing', function () {
    Event::fake();
    $connection = telegramGroupConnection();

    (new TelegramHandler)->handle($connection, [
        'update_id' => 900000003,
        'my_chat_member' => [
            'chat' => ['id' => TG_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'],
            'from' => ['id' => TG_ALICE_ID, 'is_bot' => false, 'first_name' => 'Alice'],
            'date' => 1754500000,
            'old_chat_member' => ['user' => ['id' => 42, 'is_bot' => true], 'status' => 'left'],
            'new_chat_member' => ['user' => ['id' => 42, 'is_bot' => true], 'status' => 'member'],
        ],
    ]);

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('member join/leave service messages are ignored', function () {
    Event::fake();
    $connection = telegramGroupConnection();

    (new TelegramHandler)->handle($connection, telegramGroupMessage([
        'text' => null,
        'new_chat_members' => [['id' => 999, 'first_name' => 'Novo']],
    ]));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('a supergroup migration repoints the conversation and group contact to the new chat id', function () {
    Event::fake();
    $connection = telegramGroupConnection();
    $handler = new TelegramHandler;

    $handler->handle($connection, telegramGroupMessage([
        'chat' => ['id' => -4222, 'title' => 'Time de Suporte', 'type' => 'group'],
    ]));

    $handler->handle($connection, telegramGroupMessage([
        'message_id' => 104,
        'text' => null,
        'chat' => ['id' => -4222, 'title' => 'Time de Suporte', 'type' => 'group'],
        'migrate_to_chat_id' => TG_GROUP_ID,
    ]));

    expect(Conversation::first()->external_id)->toBe((string) TG_GROUP_ID)
        ->and(Contact::where('is_group', true)->first()->external_id)->toBe((string) TG_GROUP_ID);
});

test('an anonymous admin post is attributed to the group itself and not listed as participant', function () {
    Event::fake();
    $connection = telegramGroupConnection();

    (new TelegramHandler)->handle($connection, telegramGroupMessage([
        'from' => ['id' => 1087968824, 'is_bot' => true, 'first_name' => 'Group', 'username' => 'GroupAnonymousBot'],
        'sender_chat' => ['id' => TG_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'],
    ]));

    $conversation = Conversation::first();
    $group = Contact::where('is_group', true)->first();

    expect(Message::first()->contact_id)->toBe($group->id)
        ->and($conversation->participants()->count())->toBe(0);
});

test('editing a group message updates the right chat-scoped message', function () {
    Event::fake();
    $connection = telegramGroupConnection();
    $handler = new TelegramHandler;

    $handler->handle($connection, telegramGroupMessage());

    $handler->handle($connection, [
        'update_id' => 900000002,
        'edited_message' => [
            'message_id' => 100,
            'from' => ['id' => TG_ALICE_ID, 'is_bot' => false, 'first_name' => 'Alice'],
            'chat' => ['id' => TG_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'],
            'date' => 1754500000,
            'edit_date' => 1754500100,
            'text' => 'Olá do grupo! (editado)',
        ],
    ]);

    expect(Message::first()->body)->toBe('Olá do grupo! (editado)')
        ->and(Message::first()->edited_at)->not->toBeNull();
});

test('a non-owner agent without assignment cannot access a pending group conversation (no 500)', function () {
    Event::fake();
    $connection = telegramGroupConnection();

    (new TelegramHandler)->handle($connection, telegramGroupMessage());

    // Regression: isAccessibleBy used to read Eloquent's internal
    // connection-name string and crash on ->channel for non-owner agents.
    $agent = User::factory()->create(['tenant_id' => $connection->tenant_id]);

    expect(Conversation::first()->isAccessibleBy($agent))->toBeFalse();
});

test('a private message still creates a private conversation without a per-message sender', function () {
    Event::fake();
    $connection = telegramGroupConnection();

    (new TelegramHandler)->handle($connection, telegramGroupMessage([
        'chat' => ['id' => TG_ALICE_ID, 'first_name' => 'Alice', 'type' => 'private'],
    ]));

    $conversation = Conversation::first();

    expect($conversation->type)->toBe(ConversationType::Private)
        ->and($conversation->contact->external_id)->toBe((string) TG_ALICE_ID)
        ->and(Message::first()->contact_id)->toBeNull();
});
