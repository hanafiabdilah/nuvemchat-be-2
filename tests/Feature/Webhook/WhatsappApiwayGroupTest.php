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

/**
 * Production shape (conversation 19210/19211): whatsmeow announced the group
 * sender key under the same message ID it later used for the text. The node
 * holds no content at all — no `conversation`, no media.
 */
function apiwaySenderKeyEvent(string $messageId): array
{
    $payload = apiwayGroupMessage(['ID' => $messageId]);

    $payload['event']['Message'] = [
        'messageContextInfo' => [
            'deviceListMetadata' => ['recipientKeyHash' => 'b2dWXTpxqI4GKw==', 'senderKeyHash' => 'SLF5f5hJYd/aIQ=='],
            'deviceListMetadataVersion' => 2,
        ],
        'senderKeyDistributionMessage' => [
            'axolotlSenderKeyDistributionMessage' => 'MwjOqbyyBBAAGiCmaIbvzajtOuUnXRvg0muq',
            'groupID' => APW_GROUP_JID,
        ],
    ];

    return $payload;
}

test('the sender-key distribution event that precedes a group message is dropped', function () {
    Event::fake();
    $connection = apiwayGroupConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwaySenderKeyEvent('MSG-SKDM-1'));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('a sender-key event and the content event for one message yield a single conversation and message', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    // Both events carry the same ID; only the second one has a body.
    $handler->handle($connection, apiwaySenderKeyEvent('3B2E18EDC15939093EFE'));
    $handler->handle($connection, apiwayGroupMessage(['ID' => '3B2E18EDC15939093EFE'], ['conversation' => 'weee']));

    // …and the group keeps taking later messages in that same conversation.
    $handler->handle($connection, apiwayGroupMessage(['ID' => '3BF2D0F890F0B99E885B'], ['conversation' => 'wew']));

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and(Message::pluck('body')->all())->toBe(['weee', 'wew'])
        ->and(Message::where('message_type', \App\Enums\Message\MessageType::Unsupported)->count())->toBe(0);
});

test('status posts and broadcast lists never become conversations', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    // Other people's Stories — whatsmeow flags these IsGroup.
    $handler->handle($connection, apiwayGroupMessage([
        'ID' => 'MSG-STATUS-1',
        'Chat' => 'status@broadcast',
    ], ['conversation' => 'minha story']));

    // Sender-side echo of a broadcast-list send.
    $handler->handle($connection, apiwayGroupMessage([
        'ID' => 'MSG-BCAST-1',
        'Chat' => '1623173607@broadcast',
        'IsFromMe' => true,
    ], ['conversation' => 'promo']));

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0);

    // A real group on the same connection is unaffected.
    $handler->handle($connection, apiwayGroupMessage());

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::first()->external_id)->toBe(APW_GROUP_JID);
});

/**
 * Verbatim shape of the reaction whatsmeow sent for group
 * 555491607349-1623173607@g.us. `key.ID` is the message being reacted to;
 * `key.participant` is who *sent* that message, not who reacted.
 */
function apiwayReaction(string $targetId, string $emoji, array $infoOverrides = []): array
{
    $payload = apiwayGroupMessage(array_merge(['ID' => 'REACT-'.$targetId.'-'.md5($emoji), 'Type' => 'reaction'], $infoOverrides));

    $payload['event']['Message'] = [
        'reactionMessage' => [
            'key' => [
                'ID' => $targetId,
                'fromMe' => false,
                'participant' => '9359337734267@lid',
                'remoteJID' => APW_GROUP_JID,
            ],
            'senderTimestampMS' => 1786343613105,
            'text' => $emoji,
        ],
    ];

    return $payload;
}

test('a group reaction attaches to the target message instead of becoming an unsupported bubble', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage(['ID' => 'TARGET-1'], ['conversation' => 'Blz']));
    $handler->handle($connection, apiwayReaction('TARGET-1', '👍🏻'));

    $target = Message::where('external_id', 'TARGET-1')->first();

    expect(Message::count())->toBe(1) // the reaction is not a message of its own
        ->and(Message::where('message_type', \App\Enums\Message\MessageType::Unsupported)->count())->toBe(0)
        ->and($target->reactions)->toHaveCount(1)
        ->and($target->reactions->first()->emoji)->toBe('👍🏻');
});

test('several group members can react to the same message without overwriting each other', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage(['ID' => 'TARGET-2'], ['conversation' => 'Blz']));

    $handler->handle($connection, apiwayReaction('TARGET-2', '👍🏻'));
    $handler->handle($connection, apiwayReaction('TARGET-2', '❤️', [
        'Sender' => '44226083565660@lid',
        'SenderAlt' => APW_BOB_PHONE.'@s.whatsapp.net',
        'PushName' => 'Bob',
    ]));

    $target = Message::where('external_id', 'TARGET-2')->first();
    $emojis = $target->reactions()->pluck('emoji')->all();

    // Under the old (message_id, sender_type) key Bob's ❤️ overwrote Alice's 👍🏻.
    expect($target->reactions()->count())->toBe(2)
        ->and($emojis)->toContain('👍🏻')
        ->and($emojis)->toContain('❤️')
        ->and($target->reactions()->pluck('contact_id')->filter()->unique())->toHaveCount(2);
});

test('a member changing their reaction replaces only their own', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage(['ID' => 'TARGET-3'], ['conversation' => 'Blz']));

    // Alice reacts, Bob reacts, then Alice changes her mind.
    $handler->handle($connection, apiwayReaction('TARGET-3', '👍🏻'));
    $handler->handle($connection, apiwayReaction('TARGET-3', '❤️', [
        'Sender' => '44226083565660@lid',
        'SenderAlt' => APW_BOB_PHONE.'@s.whatsapp.net',
        'PushName' => 'Bob',
    ]));
    $handler->handle($connection, apiwayReaction('TARGET-3', '😂'));

    $target = Message::where('external_id', 'TARGET-3')->first();
    $emojis = $target->reactions()->pluck('emoji')->sort()->values()->all();

    expect($target->reactions()->count())->toBe(2)
        ->and($emojis)->toContain('😂')
        ->and($emojis)->toContain('❤️')
        ->and($emojis)->not->toContain('👍🏻');
});

test('an empty reaction text removes that member reaction only', function () {
    Event::fake();
    $connection = apiwayGroupConnection();
    $handler = new WhatsappApiwayHandler;

    $handler->handle($connection, apiwayGroupMessage(['ID' => 'TARGET-4'], ['conversation' => 'Blz']));
    $handler->handle($connection, apiwayReaction('TARGET-4', '👍🏻'));
    $handler->handle($connection, apiwayReaction('TARGET-4', '❤️', [
        'Sender' => '44226083565660@lid',
        'SenderAlt' => APW_BOB_PHONE.'@s.whatsapp.net',
        'PushName' => 'Bob',
    ]));

    $handler->handle($connection, apiwayReaction('TARGET-4', '')); // Alice un-reacts

    $target = Message::where('external_id', 'TARGET-4')->first();

    expect($target->reactions()->count())->toBe(1)
        ->and($target->reactions()->first()->emoji)->toBe('❤️');
});

test('a reaction to an unknown message is ignored', function () {
    Event::fake();
    $connection = apiwayGroupConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayReaction('NEVER-SEEN', '👍🏻'));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});
