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
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function officialContactConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['access_token' => 'test-token', 'phone_number_id' => '1083508778182246'],
    ]);
}

/**
 * Verbatim production payload. Note `vcard`: base64, not the plain vCard Meta's
 * own docs show.
 */
function officialContactPayload(?array $contacts = null): array
{
    return [
        'id' => '1463349248861966',
        'changes' => [[
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'display_phone_number' => '555481061675',
                    'phone_number_id' => '1083508778182246',
                ],
                'contacts' => [[
                    'profile' => ['name' => 'Hanafi Abdilah'],
                    'wa_id' => '6282122787699',
                    'user_id' => 'ID.2222488025161193',
                ]],
                'messages' => [[
                    'from' => '6282122787699',
                    'from_user_id' => 'ID.2222488025161193',
                    'id' => 'wamid.HBgNNjI4MjEyMjc4NzY5ORUCABIYFDNCOUJFMzZBNTkxMUREMjJDREQwAA==',
                    'timestamp' => '1786621132',
                    'type' => 'contacts',
                    'contacts' => $contacts ?? [[
                        'name' => ['first_name' => 'Hanafi', 'formatted_name' => 'Hanafi'],
                        'phones' => [['phone' => '+62 821-2278-7699', 'wa_id' => '6282122787699']],
                        'vcard' => 'QkVHSU46VkNBUkQKVkVSU0lPTjozLjAKTjo7SGFuYWZpOzs7CkZOOkhhbmFmaQpURUw7d2FpZD02MjgyMTIyNzg3Njk5Ois2MiA4MjEtMjI3OC03Njk5CkVORDpWQ0FSRA==',
                        'origin' => 'other',
                    ]],
                ]],
            ],
            'field' => 'messages',
        ]],
    ];
}

test('a shared contact card is stored as a contact message, not unsupported', function () {
    Event::fake();
    $connection = officialContactConnection();

    (new WhatsappOfficialHandler)->handle($connection, officialContactPayload());

    $message = Message::first();

    expect($message)->not->toBeNull()
        ->and($message->message_type)->toBe(MessageType::Contact)
        // The card has no text; every reader of `body` (list preview, flow,
        // search, copy) gets the name instead of an empty bubble.
        ->and($message->body)->toBe('Hanafi');
});

test('the API resource serves the parsed card', function () {
    Event::fake();
    $connection = officialContactConnection();

    (new WhatsappOfficialHandler)->handle($connection, officialContactPayload());

    $meta = MessageResource::make(Message::first()->load('conversation.connection'))
        ->toArray(request())['meta'];

    expect($meta['contacts'])->toHaveCount(1)
        ->and($meta['contacts'][0]['name'])->toBe('Hanafi')
        ->and($meta['contacts'][0]['phones'])->toBe([
            ['number' => '+62 821-2278-7699', 'wa_id' => '6282122787699'],
        ])
        // Base64 in, readable vCard out.
        ->and($meta['contacts'][0]['vcard'])->toStartWith('BEGIN:VCARD');
});

test('a card whose numbers Meta did not break out falls back to the vCard', function () {
    Event::fake();
    $connection = officialContactConnection();

    (new WhatsappOfficialHandler)->handle($connection, officialContactPayload([[
        'name' => [],
        'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:Only In Card\nTEL;waid=5511999998888:+55 11 99999-8888\nEND:VCARD",
    ]]));

    $meta = MessageResource::make(Message::first()->load('conversation.connection'))
        ->toArray(request())['meta'];

    expect($meta['contacts'][0]['name'])->toBe('Only In Card')
        ->and($meta['contacts'][0]['phones'])->toBe([
            ['number' => '+55 11 99999-8888', 'wa_id' => '5511999998888'],
        ]);
});

test('several cards in one message all arrive', function () {
    Event::fake();
    $connection = officialContactConnection();

    (new WhatsappOfficialHandler)->handle($connection, officialContactPayload([
        [
            'name' => ['formatted_name' => 'Hanafi'],
            'phones' => [['phone' => '+62 821-2278-7699', 'wa_id' => '6282122787699']],
            'vcard' => "BEGIN:VCARD\nFN:Hanafi\nEND:VCARD",
        ],
        [
            // No formatted_name: the parts are joined instead.
            'name' => ['first_name' => 'Jane', 'last_name' => 'Roe'],
            'phones' => [['phone' => '+55 11 99999-8888', 'wa_id' => '5511999998888']],
            'vcard' => "BEGIN:VCARD\nFN:Jane Roe\nEND:VCARD",
        ],
    ]));

    $message = Message::first();
    $meta = MessageResource::make($message->load('conversation.connection'))->toArray(request())['meta'];

    expect($message->body)->toBe('Hanafi, Jane Roe')
        ->and($meta['contacts'])->toHaveCount(2)
        ->and($meta['contacts'][1]['name'])->toBe('Jane Roe');
});

test('a plain vCard is left alone rather than run through base64_decode', function () {
    $plain = "BEGIN:VCARD\nFN:Plain\nTEL;waid=1:+1\nEND:VCARD";

    $card = VCard::cardsFromCloudApi([['name' => [], 'vcard' => $plain]])[0];

    expect($card['vcard'])->toBe($plain)
        ->and($card['name'])->toBe('Plain');
});
