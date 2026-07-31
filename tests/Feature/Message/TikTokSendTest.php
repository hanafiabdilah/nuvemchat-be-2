<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\Handlers\TikTokHandler as TikTokMessageHandler;
use App\Services\V1\SendMessage\Handlers\TikTokHandler as TikTokV1Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function tiktokSendConnection(): Connection
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
 * A conversation whose last inbound message arrived $hoursAgo hours ago.
 */
function tiktokConversationWithInbound(Connection $connection, int $hoursAgo): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => 'USER-1',
        'channel' => Channel::TikTok,
        'name' => 'someuser',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => 'CONV-1',
        'status' => ConversationStatus::Active,
    ]);

    $conversation->messages()->create([
        'external_id' => 'MSG-IN-1',
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'hi',
        'sent_at' => now()->subHours($hoursAgo),
        'delivery_at' => now()->subHours($hoursAgo),
    ]);

    return $conversation;
}

function fakeTikTokSendApi(): void
{
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/business/message/send/*' => Http::response([
            'code' => 0,
            'message' => 'OK',
            'data' => ['message' => ['message_id' => 'MSG-API-1']],
        ]),
    ]);
}

test('a reply inside the 48h window is sent and stored as outgoing', function () {
    Event::fake();
    fakeTikTokSendApi();

    $conversation = tiktokConversationWithInbound(tiktokSendConnection(), 47);

    $message = (new TikTokMessageHandler)->handleSendMessage($conversation, ['message' => 'hello back']);

    expect($message->external_id)->toBe('MSG-API-1')
        ->and($message->sender_type)->toBe(SenderType::Outgoing)
        ->and($message->body)->toBe('hello back');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/business/message/send/')
            && $request['recipient'] === 'CONV-1'
            && $request['text']['body'] === 'hello back';
    });
});

test('a reply after the 48h window is rejected before calling the API', function () {
    Event::fake();
    Http::fake();

    $conversation = tiktokConversationWithInbound(tiktokSendConnection(), 49);

    expect(fn () => (new TikTokMessageHandler)->handleSendMessage($conversation, ['message' => 'too late']))
        ->toThrow(ConnectionException::class);

    Http::assertNothingSent();
    expect($conversation->messages()->where('sender_type', SenderType::Outgoing)->count())->toBe(0);
});

test('a conversation with no inbound message cannot be messaged', function () {
    Event::fake();
    Http::fake();

    $connection = tiktokSendConnection();
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => 'USER-2',
        'channel' => Channel::TikTok,
        'name' => 'quietuser',
    ]);
    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => 'CONV-2',
        'status' => ConversationStatus::Active,
    ]);

    expect(fn () => (new TikTokMessageHandler)->handleSendMessage($conversation, ['message' => 'hello?']))
        ->toThrow(ConnectionException::class);
});

test('audio, video, document, edit and delete are rejected as unsupported', function () {
    $conversation = tiktokConversationWithInbound(tiktokSendConnection(), 1);
    $handler = new TikTokMessageHandler;

    expect(fn () => $handler->handleSendAudio($conversation, []))->toThrow(Exception::class, 'only supports text and image')
        ->and(fn () => $handler->handleSendVideo($conversation, []))->toThrow(Exception::class, 'only supports text and image')
        ->and(fn () => $handler->handleSendDocument($conversation, []))->toThrow(Exception::class, 'only supports text and image');
});

test('the V1 API replies into an existing conversation and stores the message', function () {
    Event::fake();
    fakeTikTokSendApi();

    $connection = tiktokSendConnection();
    $conversation = tiktokConversationWithInbound($connection, 1);

    $result = (new TikTokV1Handler)->handleSendMessage($connection, [
        'conversation_id' => 'CONV-1',
        'message' => 'api reply',
    ]);

    expect($result['message_id'])->toBe('MSG-API-1')
        ->and($conversation->messages()->where('external_id', 'MSG-API-1')->exists())->toBeTrue();
});

test('the V1 API rejects an unknown conversation with a validation error', function () {
    Event::fake();
    Http::fake();

    $connection = tiktokSendConnection();

    expect(fn () => (new TikTokV1Handler)->handleSendMessage($connection, [
        'conversation_id' => 'CONV-NOPE',
        'message' => 'hello',
    ]))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

test('the V1 API rejects a closed 48h window with a validation error', function () {
    Event::fake();
    Http::fake();

    $connection = tiktokSendConnection();
    tiktokConversationWithInbound($connection, 49);

    expect(fn () => (new TikTokV1Handler)->handleSendMessage($connection, [
        'conversation_id' => 'CONV-1',
        'message' => 'too late',
    ]))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});
