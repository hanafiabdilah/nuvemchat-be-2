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
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const APW_REVOKE_PHONE = '6285899367071';

function apiwayRevokeConnection(): Connection
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

/** An existing message on this connection — the one the revoke will target. */
function apiwayRevokeTarget(Connection $connection, SenderType $senderType = SenderType::Incoming): Message
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'name' => 'mutiamuripa',
        'external_id' => APW_REVOKE_PHONE,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => APW_REVOKE_PHONE,
        'status' => ConversationStatus::Active,
    ]);

    return $conversation->messages()->create([
        'external_id' => 'MSG-VICTIM',
        'sender_type' => $senderType,
        'message_type' => MessageType::Text,
        'body' => 'oops, wrong chat',
        'sent_at' => '2026-07-21 13:30:00',
    ]);
}

/**
 * "Delete for everyone" as whatsmeow forwards it: an ordinary Message event
 * whose only payload is a REVOKE protocol node. `Info.ID` is the revoke's own
 * id (never stored); the victim is in `protocolMessage.key.ID`.
 */
function apiwayRevokeEvent(array $protocol, bool $fromMe = false): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => 'MSG-REVOKE-1',
                'Chat' => '270514354389042@lid',
                'Sender' => '270514354389042@lid',
                'SenderAlt' => APW_REVOKE_PHONE.':12@s.whatsapp.net',
                'RecipientAlt' => APW_REVOKE_PHONE.':12@s.whatsapp.net',
                'PushName' => 'mutiamuripa',
                'IsFromMe' => $fromMe,
                'IsGroup' => false,
                'Timestamp' => '2026-07-21T10:34:28-03:00',
                'Type' => 'text',
            ],
            'Message' => [
                'messageContextInfo' => ['deviceListMetadataVersion' => 2],
                'protocolMessage' => $protocol,
            ],
        ],
    ];
}

test('a revoke with the numeric proto enum marks the message deleted', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    $target = apiwayRevokeTarget($connection);

    // REVOKE is enum 0, and production payloads carry the number, not the name.
    (new WhatsappApiwayHandler)->handle($connection, apiwayRevokeEvent([
        'key' => [
            'remoteJID' => APW_REVOKE_PHONE.'@s.whatsapp.net',
            'fromMe' => false,
            'ID' => 'MSG-VICTIM',
        ],
        'type' => 0,
    ]));

    // Stamped with the event's instant, normalised out of UTC-3 like every
    // other timestamp on this channel.
    expect(DB::table('messages')->where('external_id', 'MSG-VICTIM')->value('unsend_at'))
        ->toBe('2026-07-21 13:34:28')
        ->and(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1);

    Event::assertDispatched(MessageUpdated::class, fn ($e) => $e->message->id === $target->id);
});

test('a revoke with the enum name marks the message deleted', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    apiwayRevokeTarget($connection);

    (new WhatsappApiwayHandler)->handle($connection, apiwayRevokeEvent([
        'key' => ['ID' => 'MSG-VICTIM'],
        'type' => 'REVOKE',
    ]));

    expect(Message::first()->unsend_at)->not->toBeNull();
});

test('a revoke whose zero-valued type was omitted is still recognised', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    apiwayRevokeTarget($connection);

    // A marshaller that drops zero values leaves the node with just the key.
    (new WhatsappApiwayHandler)->handle($connection, apiwayRevokeEvent([
        'key' => ['ID' => 'MSG-VICTIM'],
    ]));

    expect(Message::first()->unsend_at)->not->toBeNull();
});

test('a message deleted from the connected phone is marked too', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    apiwayRevokeTarget($connection, SenderType::Outgoing);

    (new WhatsappApiwayHandler)->handle($connection, apiwayRevokeEvent([
        'key' => ['fromMe' => true, 'ID' => 'MSG-VICTIM'],
        'type' => 0,
    ], fromMe: true));

    // The revoke must not be recorded as an outgoing message of its own.
    expect(Message::count())->toBe(1)
        ->and(Message::first()->unsend_at)->not->toBeNull();
});

test('a repeated revoke keeps the first deletion time', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    apiwayRevokeTarget($connection);

    $event = apiwayRevokeEvent(['key' => ['ID' => 'MSG-VICTIM'], 'type' => 0]);
    (new WhatsappApiwayHandler)->handle($connection, $event);

    $event['event']['Info']['Timestamp'] = '2026-07-21T11:00:00-03:00';
    (new WhatsappApiwayHandler)->handle($connection, $event);

    expect(DB::table('messages')->where('external_id', 'MSG-VICTIM')->value('unsend_at'))
        ->toBe('2026-07-21 13:34:28');

    // Re-broadcast anyway: the first one may have missed an offline panel.
    Event::assertDispatchedTimes(MessageUpdated::class, 2);
});

test('a revoke for a message we never stored is a no-op', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayRevokeEvent([
        'key' => ['ID' => 'MSG-UNKNOWN'],
        'type' => 0,
    ]));

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('a revoke never crosses connections', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    apiwayRevokeTarget($connection);

    // Same external_id, different tenant's instance — must stay untouched.
    (new WhatsappApiwayHandler)->handle(apiwayRevokeConnection(), apiwayRevokeEvent([
        'key' => ['ID' => 'MSG-VICTIM'],
        'type' => 0,
    ]));

    expect(Message::first()->unsend_at)->toBeNull();
    Event::assertNotDispatched(MessageUpdated::class);
});

test('a typeless protocol message carrying other payload is not treated as a revoke', function () {
    Event::fake();
    $connection = apiwayRevokeConnection();
    apiwayRevokeTarget($connection);

    (new WhatsappApiwayHandler)->handle($connection, apiwayRevokeEvent([
        'key' => ['ID' => 'MSG-VICTIM'],
        'historySyncNotification' => ['fileLength' => 1234],
    ]));

    expect(Message::first()->unsend_at)->toBeNull();
});
