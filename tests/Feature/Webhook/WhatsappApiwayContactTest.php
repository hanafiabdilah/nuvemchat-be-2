<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\MessageType;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\VCard;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function apiwayContactConnection(): Connection
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

/** whatsmeow message event carrying whatever sits under `Message`. */
function apiwayContactEvent(array $message, string $id = 'MSG-CONTACT-1'): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $id,
                'Chat' => '6285899367071@s.whatsapp.net',
                'Sender' => '6285899367071@s.whatsapp.net',
                'SenderAlt' => '6285899367071:12@s.whatsapp.net',
                'PushName' => 'mutiamuripa',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-08-13T10:00:00Z',
            ],
            'Message' => $message,
        ],
    ];
}

/** Real WhatsApp vCard: grouped properties, `waid`, formatted number. */
function johnDoeVcard(): string
{
    return "BEGIN:VCARD\nVERSION:3.0\nN:;John Doe;;;\nFN:John Doe\n"
        ."item1.TEL;waid=6281234567890:+62 812-3456-7890\nitem1.X-ABLabel:Celular\nEND:VCARD";
}

test('a shared contact card is stored as a contact message with the name as its body', function () {
    Event::fake();
    $connection = apiwayContactConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayContactEvent([
        'contactMessage' => [
            'displayName' => 'John Doe',
            'vcard' => johnDoeVcard(),
        ],
    ]));

    $message = Message::first();

    expect($message)->not->toBeNull()
        ->and($message->message_type)->toBe(MessageType::Contact)
        // The card has no text; every reader of `body` (list preview, flow,
        // search, copy) gets the name instead of an empty bubble.
        ->and($message->body)->toBe('John Doe');
});

test('the API resource serves the parsed card', function () {
    Event::fake();
    $connection = apiwayContactConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayContactEvent([
        'contactMessage' => [
            'displayName' => 'John Doe',
            'vcard' => johnDoeVcard(),
        ],
    ]));

    $meta = MessageResource::make(Message::first()->load('conversation.connection'))
        ->toArray(request())['meta'];

    expect($meta['contacts'])->toHaveCount(1)
        ->and($meta['contacts'][0]['name'])->toBe('John Doe')
        ->and($meta['contacts'][0]['phones'])->toBe([
            ['number' => '+62 812-3456-7890', 'wa_id' => '6281234567890'],
        ]);
});

test('several cards in one message all arrive', function () {
    Event::fake();
    $connection = apiwayContactConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayContactEvent([
        'contactsArrayMessage' => [
            'displayName' => '2 contacts',
            'contacts' => [
                ['displayName' => 'John Doe', 'vcard' => johnDoeVcard()],
                ['displayName' => 'Jane Roe', 'vcard' => "BEGIN:VCARD\nFN:Jane Roe\nTEL;type=CELL;waid=5511999998888:+55 11 99999-8888\nEND:VCARD"],
            ],
        ],
    ]));

    $message = Message::first();
    $meta = MessageResource::make($message->load('conversation.connection'))->toArray(request())['meta'];

    expect($message->message_type)->toBe(MessageType::Contact)
        ->and($message->body)->toBe('John Doe, Jane Roe')
        ->and($meta['contacts'])->toHaveCount(2)
        ->and($meta['contacts'][1]['phones'][0]['wa_id'])->toBe('5511999998888');
});

test('a card with no displayName falls back to the vCard FN', function () {
    $card = VCard::toCard(null, johnDoeVcard());

    expect($card['name'])->toBe('John Doe');
});

test('a folded vCard line still yields one whole number', function () {
    // A long value continues on the next line, marked by a leading space.
    $vcard = "BEGIN:VCARD\nFN:Long Number\nTEL;waid=6281234567890:+62 812-\n 3456-7890\nEND:VCARD";

    expect(VCard::parse($vcard)['phones'])->toBe([
        ['number' => '+62 812-3456-7890', 'wa_id' => '6281234567890'],
    ]);
});

test('a card carrying no phone number is still shown by name', function () {
    $card = VCard::toCard('Sem Telefone', "BEGIN:VCARD\nFN:Sem Telefone\nEND:VCARD");

    expect($card['name'])->toBe('Sem Telefone')
        ->and($card['phones'])->toBe([]);
});

/**
 * Location shares the payload-shape lookup the contact card uses. Rows written
 * before the channel moved to whatsmeow keep the old `msgContent` node, and
 * both have to keep rendering.
 */
test('location meta reads both the whatsmeow node and legacy rows', function () {
    Event::fake();
    $connection = apiwayContactConnection();

    (new WhatsappApiwayHandler)->handle($connection, apiwayContactEvent([
        'locationMessage' => ['degreesLatitude' => -23.5505, 'degreesLongitude' => -46.6333],
    ], 'MSG-LOCATION-1'));

    $message = Message::first();
    $meta = MessageResource::make($message->load('conversation.connection'))->toArray(request())['meta'];

    expect($message->message_type)->toBe(MessageType::Location)
        ->and($meta['location']['latitude'])->toBe(-23.5505);

    $message->update(['meta' => ['msgContent' => [
        'locationMessage' => ['degreesLatitude' => 1.5, 'degreesLongitude' => 2.5],
    ]]]);

    $legacy = MessageResource::make($message->fresh()->load('conversation.connection'))
        ->toArray(request())['meta'];

    expect($legacy['location']['longitude'])->toBe(2.5);
});
