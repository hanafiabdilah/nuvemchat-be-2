<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const APW_GROUP_JID = '555491607349-1623173607@g.us';
const APW_ALICE_LID = '45148847243518@lid';
const APW_ALICE_PHONE = '555491094949';
const APW_BOB_PHONE = '555498882211';

function apiwayGroupConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);
}

/**
 * Real-world whatsmeow group message shape: LID addressing, phone in
 * SenderAlt, no group subject anywhere in the event.
 */
function apiwayGroupMessage(array $infoOverrides = [], array $messageOverrides = []): array
{
    return [
        'type' => 'Message',
        'instanceName' => 'apiway-30',
        'event' => [
            'Info' => array_merge([
                'AddressingMode' => 'lid',
                'ID' => 'A5F025C96D4757F73E49C8AE3C3983CD',
                'Chat' => APW_GROUP_JID,
                'Sender' => APW_ALICE_LID,
                'SenderAlt' => APW_ALICE_PHONE . '@s.whatsapp.net',
                'PushName' => 'Loja Detalhe',
                'IsFromMe' => false,
                'IsGroup' => true,
                'Timestamp' => '2026-08-07T10:29:56-03:00',
                'Type' => 'text',
            ], $infoOverrides),
            'Message' => array_merge(['conversation' => 'Blz'], $messageOverrides),
        ],
    ];
}

test('a group message creates a group conversation keyed by the group JID with a per-message sender', function () {
    Event::fake();
    $connection = apiwayGroupConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayGroupMessage());

    $conversation = Conversation::first();
    $message = Message::first();
    $group = Contact::where('is_group', true)->first();
    $alice = Contact::where('external_id', APW_ALICE_PHONE)->first();

    expect($conversation->type)->toBe(ConversationType::Group)
        ->and($conversation->external_id)->toBe(APW_GROUP_JID)
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($group->name)->toBe('555491607349-1623173607') // JID placeholder until a GroupInfo event
        ->and($conversation->contact_id)->toBe($group->id)
        ->and($alice->name)->toBe('Loja Detalhe')
        ->and($alice->is_group)->toBeFalse()
        ->and($message->body)->toBe('Blz')
        ->and($message->sender_type)->toBe(SenderType::Incoming)
        ->and($message->contact_id)->toBe($alice->id)
        ->and($conversation->participants()->pluck('contacts.id')->all())->toBe([$alice->id])
        ->and(FlowState::count())->toBe(0);
});

test('messages from different members share the conversation and register participants', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage());
    $handler->handle($connection, apiwayGroupMessage([
        'ID' => 'MSG-BOB-1',
        'Sender' => '99887766554433@lid',
        'SenderAlt' => APW_BOB_PHONE . '@s.whatsapp.net',
        'PushName' => 'Bob',
    ], ['conversation' => 'Oi']));

    $conversation = Conversation::first();

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and($conversation->participants()->count())->toBe(2)
        ->and(Message::pluck('contact_id')->unique()->count())->toBe(2);
});

test('an IsFromMe echo lands as outgoing in the group conversation without a sender contact', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage());
    $handler->handle($connection, apiwayGroupMessage([
        'ID' => 'MSG-ME-1',
        'IsFromMe' => true,
        'Sender' => '111@lid',
        'SenderAlt' => '555400000001@s.whatsapp.net',
        'PushName' => 'Me',
    ], ['conversation' => 'resposta do celular']));

    $echo = Message::where('external_id', 'MSG-ME-1')->first();
    $conversation = Conversation::first();

    expect(Conversation::count())->toBe(1)
        ->and($echo->sender_type)->toBe(SenderType::Outgoing)
        ->and($echo->contact_id)->toBeNull()
        ->and($conversation->participants()->count())->toBe(1); // only Alice
});

test('a duplicate group message id is ignored', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage());
    $handler->handle($connection, apiwayGroupMessage());

    expect(Message::count())->toBe(1);
});

test('a GroupInfo subject change renames the group contact without creating a message', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage());
    $handler->handle($connection, [
        'type' => 'GroupInfo',
        'event' => [
            'JID' => APW_GROUP_JID,
            'Name' => ['Name' => 'Equipe Vendas', 'NameSetBy' => APW_ALICE_LID],
        ],
    ]);

    expect(Message::count())->toBe(1)
        ->and(Contact::where('is_group', true)->first()->name)->toBe('Equipe Vendas');

    // Later messages keep the real subject — the JID placeholder never wins back.
    $handler->handle($connection, apiwayGroupMessage(['ID' => 'MSG-AFTER-RENAME']));

    expect(Contact::where('is_group', true)->first()->name)->toBe('Equipe Vendas');
});

test('a JoinedGroup event primes the group name before the first message', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    // types.GroupName is embedded in JoinedGroup, so Name arrives flat.
    $handler->handle($connection, [
        'type' => 'JoinedGroup',
        'event' => [
            'JID' => APW_GROUP_JID,
            'Name' => 'Suporte VIP',
        ],
    ]);

    expect(Conversation::count())->toBe(0)
        ->and(Contact::where('is_group', true)->first()->name)->toBe('Suporte VIP');

    $handler->handle($connection, apiwayGroupMessage());

    expect(Contact::where('is_group', true)->first()->name)->toBe('Suporte VIP')
        ->and(Conversation::first()->type)->toBe(ConversationType::Group);
});

test('protocol messages inside groups are dropped', function () {
    Event::fake();
    $connection = apiwayGroupConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayGroupMessage(
        ['ID' => 'MSG-PROTO-1'],
        ['protocolMessage' => ['type' => 'REVOKE'], 'conversation' => null],
    ));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});
