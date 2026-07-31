<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\TikTok\TikTokConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const TIKTOK_TEST_SECRET = 'tiktok-app-secret';

function tiktokConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::TikTok,
        'name' => 'TikTok',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'business_id' => 'BIZ-1',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addDay()->toDateTimeString(),
            'refresh_token_expires_at' => now()->addDays(30)->toDateTimeString(),
        ],
    ]);
}

/**
 * TikTok delivers the event envelope with `content` as a JSON *string*.
 */
function tiktokEventBody(string $event, array $content): string
{
    return json_encode([
        'event' => $event,
        'user_openid' => 'BIZ-1',
        'content' => json_encode($content),
    ]);
}

function tiktokTextContent(array $overrides = []): array
{
    return array_merge([
        'conversation_id' => 'CONV-1',
        'message_id' => 'MSG-1',
        'timestamp' => now()->getTimestampMs(),
        'type' => 'text',
        'text' => ['body' => 'hello from tiktok'],
        'from' => 'someuser',
        'from_user' => ['id' => 'USER-1'],
        'to' => 'mybusiness',
        'to_user' => ['id' => 'BIZ-1'],
    ], $overrides);
}

function postTikTokWebhook(string $body, string $secret = TIKTOK_TEST_SECRET, ?int $timestamp = null): TestResponse
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return test()->call('POST', '/webhook/tiktok', [], [], [], [
        'HTTP_TIKTOK_SIGNATURE' => "t={$timestamp},s={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

beforeEach(function () {
    Setting::set(TikTokConfig::KEY_APP_SECRET, TIKTOK_TEST_SECRET);
});

test('an inbound DM creates contact, conversation and message', function () {
    Event::fake();
    $connection = tiktokConnection();

    postTikTokWebhook(tiktokEventBody('im_receive_msg', tiktokTextContent()))->assertOk();

    $contact = Contact::where('external_id', 'USER-1')->first();
    expect($contact)->not->toBeNull()
        ->and($contact->username)->toBe('someuser');

    $conversation = Conversation::where('external_id', 'CONV-1')->first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->connection_id)->toBe($connection->id);

    $message = Message::where('external_id', 'MSG-1')->first();
    expect($message)->not->toBeNull()
        ->and($message->sender_type)->toBe(SenderType::Incoming)
        ->and($message->body)->toBe('hello from tiktok');
});

test('an im_send_msg echo is stored as an outgoing message for the recipient contact', function () {
    Event::fake();
    tiktokConnection();

    postTikTokWebhook(tiktokEventBody('im_send_msg', tiktokTextContent([
        'message_id' => 'MSG-OUT-1',
        'text' => ['body' => 'reply from the tiktok app'],
        'from' => 'mybusiness',
        'from_user' => ['id' => 'BIZ-1'],
        'to' => 'someuser',
        'to_user' => ['id' => 'USER-1'],
    ])))->assertOk();

    // The contact is the conversation partner, never the business itself.
    expect(Contact::where('external_id', 'BIZ-1')->exists())->toBeFalse()
        ->and(Contact::where('external_id', 'USER-1')->exists())->toBeTrue();

    $message = Message::where('external_id', 'MSG-OUT-1')->first();
    expect($message)->not->toBeNull()
        ->and($message->sender_type)->toBe(SenderType::Outgoing);
});

test('an echo of a message already saved by the send handler is not duplicated', function () {
    Event::fake();
    tiktokConnection();

    $body = tiktokEventBody('im_receive_msg', tiktokTextContent());
    postTikTokWebhook($body)->assertOk();
    postTikTokWebhook($body)->assertOk();

    expect(Message::where('external_id', 'MSG-1')->count())->toBe(1);
});

test('a tampered body is rejected and creates nothing', function () {
    Event::fake();
    tiktokConnection();

    $body = tiktokEventBody('im_receive_msg', tiktokTextContent());
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, TIKTOK_TEST_SECRET);
    $tampered = tiktokEventBody('im_receive_msg', tiktokTextContent(['text' => ['body' => 'forged']]));

    test()->call('POST', '/webhook/tiktok', [], [], [], [
        'HTTP_TIKTOK_SIGNATURE' => "t={$timestamp},s={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $tampered)->assertStatus(401);

    expect(Message::count())->toBe(0);
});

test('a request signed with the wrong secret is rejected', function () {
    Event::fake();
    tiktokConnection();

    postTikTokWebhook(tiktokEventBody('im_receive_msg', tiktokTextContent()), 'attacker-secret')
        ->assertStatus(401);

    expect(Message::count())->toBe(0);
});

test('a stale signature timestamp is rejected', function () {
    Event::fake();
    tiktokConnection();

    postTikTokWebhook(
        tiktokEventBody('im_receive_msg', tiktokTextContent()),
        TIKTOK_TEST_SECRET,
        time() - 3600
    )->assertStatus(401);

    expect(Message::count())->toBe(0);
});

test('an event for an unknown business account is acked without creating anything', function () {
    Event::fake();
    tiktokConnection();

    $body = json_encode([
        'event' => 'im_receive_msg',
        'user_openid' => 'SOMEONE-ELSE',
        'content' => json_encode(tiktokTextContent()),
    ]);

    postTikTokWebhook($body)->assertOk();

    expect(Message::count())->toBe(0);
});

test('im_mark_read_msg marks outgoing messages up to the read timestamp', function () {
    Event::fake();
    $connection = tiktokConnection();

    // Seed a conversation with an outgoing message via an echo event.
    postTikTokWebhook(tiktokEventBody('im_send_msg', tiktokTextContent([
        'message_id' => 'MSG-OUT-1',
        'from' => 'mybusiness',
        'from_user' => ['id' => 'BIZ-1'],
        'to' => 'someuser',
        'to_user' => ['id' => 'USER-1'],
    ])))->assertOk();

    postTikTokWebhook(tiktokEventBody('im_mark_read_msg', [
        'conversation_id' => 'CONV-1',
        'read' => ['last_read_timestamp' => now()->addMinute()->getTimestampMs()],
        'from_user' => ['id' => 'USER-1'],
    ]))->assertOk();

    $message = Message::where('external_id', 'MSG-OUT-1')->first();
    expect($message->read_at)->not->toBeNull();
});
