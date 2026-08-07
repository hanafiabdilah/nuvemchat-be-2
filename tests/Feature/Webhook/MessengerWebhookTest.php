<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Meta\FacebookConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const FB_TEST_SECRET = 'fb-app-secret-for-tests';
const FB_TEST_PAGE_ID = '108999999999999';
const FB_TEST_PSID = '24000000000000001';

function messengerConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Messenger,
        'name' => 'Página',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'access_token' => 'page-token',
            'page_id' => FB_TEST_PAGE_ID,
            'page_name' => 'Minha Página',
        ],
    ]);
}

function messengerEntry(array $messaging): array
{
    return [
        'object' => 'page',
        'entry' => [[
            'id' => FB_TEST_PAGE_ID,
            'time' => 1791000000000,
            'messaging' => [$messaging],
        ]],
    ];
}

function postSignedMessengerWebhook(array $payload, ?string $secret = FB_TEST_SECRET)
{
    $body = json_encode($payload);
    $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

    return test()
        ->withHeaders(['X-Hub-Signature-256' => $signature])
        ->postJson('/webhook/facebook', $payload);
}

beforeEach(function () {
    Setting::set(FacebookConfig::KEY_APP_SECRET, FB_TEST_SECRET);
    Setting::set(FacebookConfig::KEY_WEBHOOK_VERIFY_TOKEN, 'verify-me');

    // Contact profile lookups (and any stray Graph call) stay offline.
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'name' => 'John Doe',
        ]),
    ]);
});

test('the verify endpoint echoes the challenge for the configured token', function () {
    $response = $this->get('/webhook/facebook?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345');

    $response->assertOk();
    expect($response->getContent())->toBe('12345');

    $this->get('/webhook/facebook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
        ->assertForbidden();
});

test('an inbound text creates contact, conversation and message', function () {
    Event::fake();
    $connection = messengerConnection();

    postSignedMessengerWebhook(messengerEntry([
        'sender' => ['id' => FB_TEST_PSID],
        'recipient' => ['id' => FB_TEST_PAGE_ID],
        'timestamp' => 1791000000000,
        'message' => ['mid' => 'm_abc123', 'text' => 'Olá!'],
    ]))->assertOk();

    $message = Message::first();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->name)->toBe('John Doe')
        ->and(Conversation::count())->toBe(1)
        ->and(Conversation::first()->connection_id)->toBe($connection->id)
        ->and(Conversation::first()->external_id)->toBe(FB_TEST_PSID)
        ->and($message->body)->toBe('Olá!')
        ->and($message->sender_type)->toBe(SenderType::Incoming);
});

test('an echo is stored as an outgoing message for the recipient contact', function () {
    Event::fake();
    messengerConnection();

    postSignedMessengerWebhook(messengerEntry([
        'sender' => ['id' => FB_TEST_PAGE_ID],
        'recipient' => ['id' => FB_TEST_PSID],
        'timestamp' => 1791000000000,
        'message' => ['mid' => 'm_echo1', 'text' => 'Respondido pelo celular', 'is_echo' => true],
    ]))->assertOk();

    $message = Message::first();

    expect($message->sender_type)->toBe(SenderType::Outgoing)
        ->and(Contact::first()->external_id)->toBe(FB_TEST_PSID)
        ->and(Conversation::first()->external_id)->toBe(FB_TEST_PSID);
});

test('an echo of a message already saved by the send handler is not duplicated', function () {
    Event::fake();
    $connection = messengerConnection();

    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => FB_TEST_PSID,
        'name' => 'John Doe',
        'channel' => Channel::Messenger,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => FB_TEST_PSID,
        'status' => \App\Enums\Conversation\Status::Active,
    ]);

    $conversation->messages()->create([
        'external_id' => 'm_sent_by_panel',
        'sender_type' => SenderType::Outgoing,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'Oi',
        'sent_at' => now(),
    ]);

    postSignedMessengerWebhook(messengerEntry([
        'sender' => ['id' => FB_TEST_PAGE_ID],
        'recipient' => ['id' => FB_TEST_PSID],
        'timestamp' => 1791000000000,
        'message' => ['mid' => 'm_sent_by_panel', 'text' => 'Oi', 'is_echo' => true],
    ]))->assertOk();

    expect(Message::count())->toBe(1);
});

test('a tampered body is rejected and creates nothing', function () {
    Event::fake();
    messengerConnection();

    $payload = messengerEntry([
        'sender' => ['id' => FB_TEST_PSID],
        'recipient' => ['id' => FB_TEST_PAGE_ID],
        'timestamp' => 1791000000000,
        'message' => ['mid' => 'm_bad', 'text' => 'spoofed'],
    ]);

    postSignedMessengerWebhook($payload, 'wrong-secret')->assertUnauthorized();

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('an event for an unknown page is acked without creating anything', function () {
    Event::fake();
    messengerConnection();

    $payload = messengerEntry([
        'sender' => ['id' => FB_TEST_PSID],
        'recipient' => ['id' => '555000555000555'],
        'timestamp' => 1791000000000,
        'message' => ['mid' => 'm_unknown_page', 'text' => 'oi'],
    ]);
    $payload['entry'][0]['id'] = '555000555000555';

    postSignedMessengerWebhook($payload)->assertOk();

    expect(Message::count())->toBe(0);
});

test('a read watermark marks earlier outgoing messages as read', function () {
    Event::fake();
    $connection = messengerConnection();

    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => FB_TEST_PSID,
        'name' => 'John Doe',
        'channel' => Channel::Messenger,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => FB_TEST_PSID,
        'status' => \App\Enums\Conversation\Status::Active,
    ]);

    $read = $conversation->messages()->create([
        'external_id' => 'm_out_1',
        'sender_type' => SenderType::Outgoing,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'antes do watermark',
        'sent_at' => 1791000000, // seconds
    ]);

    $unread = $conversation->messages()->create([
        'external_id' => 'm_out_2',
        'sender_type' => SenderType::Outgoing,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'depois do watermark',
        'sent_at' => 1791000500,
    ]);

    postSignedMessengerWebhook(messengerEntry([
        'sender' => ['id' => FB_TEST_PSID],
        'recipient' => ['id' => FB_TEST_PAGE_ID],
        'timestamp' => 1791000100000,
        'read' => ['watermark' => 1791000100000], // ms
    ]))->assertOk();

    expect($read->fresh()->read_at)->not->toBeNull()
        ->and($unread->fresh()->read_at)->toBeNull();
});

test('a postback is stored as a text message with the button title', function () {
    Event::fake();
    messengerConnection();

    postSignedMessengerWebhook(messengerEntry([
        'sender' => ['id' => FB_TEST_PSID],
        'recipient' => ['id' => FB_TEST_PAGE_ID],
        'timestamp' => 1791000000000,
        'postback' => ['mid' => 'm_postback1', 'title' => 'Falar com atendente', 'payload' => 'TALK_TO_AGENT'],
    ]))->assertOk();

    $message = Message::first();

    expect($message->body)->toBe('Falar com atendente')
        ->and($message->sender_type)->toBe(SenderType::Incoming);
});
