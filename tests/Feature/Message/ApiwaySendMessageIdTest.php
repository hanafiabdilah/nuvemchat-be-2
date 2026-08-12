<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\Handlers\WhatsappApiwayHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler as WhatsappApiwayWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function apiwaySendConversation(): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);

    return Conversation::create([
        'tenant_id' => $tenant->id,
        'contact_id' => Contact::create([
            'tenant_id' => $tenant->id,
            'external_id' => '6285899367071',
            'name' => 'mutiamuripa',
        ])->id,
        'connection_id' => $connection->id,
        'external_id' => '6285899367071',
        'status' => ConversationStatus::Active,
    ]);
}

/**
 * The shape the current core build returns: the node payload sits two `data`
 * envelopes deep and the id key is capitalised. Reading only `data.id` left
 * every panel-sent message with an empty external_id, so receipts, edits,
 * deletes and reactions had nothing to match — the ticks froze on "sent".
 */
test('the message id is read from the double-wrapped core send response', function () {
    Event::fake();
    Http::fake([
        '*/v1/message/send-text*' => Http::response([
            'success' => true,
            'data' => [
                'code' => 200,
                'data' => ['Details' => 'Sent', 'Id' => '3EB03C46D1D634451AD993', 'Timestamp' => 1786494578],
                'success' => true,
            ],
        ]),
    ]);

    $message = (new WhatsappApiwayHandler)->handleSendMessage(apiwaySendConversation(), ['message' => 'oi']);

    expect($message->external_id)->toBe('3EB03C46D1D634451AD993');
});

test('older core response shapes still resolve', function () {
    $handler = new WhatsappApiwayHandler;

    expect($handler->getMessageId(['success' => true, 'data' => ['id' => 'FLAT-ID']]))->toBe('FLAT-ID')
        ->and($handler->getMessageId(['success' => true, 'data' => 'BARE-ID']))->toBe('BARE-ID')
        ->and($handler->getMessageId(['data' => ['key' => ['ID' => 'KEY-ID']]]))->toBe('KEY-ID')
        ->and($handler->getMessageId(['messageId' => 'TOP-ID']))->toBe('TOP-ID')
        ->and($handler->getMessageId(['success' => false]))->toBe('');
});

test('a delivery receipt ticks a message sent from the panel', function () {
    Event::fake();
    Http::fake([
        '*/v1/message/send-text*' => Http::response([
            'success' => true,
            'data' => [
                'code' => 200,
                'data' => ['Details' => 'Sent', 'Id' => '3EB0AAA111', 'Timestamp' => 1786494578],
                'success' => true,
            ],
        ]),
    ]);

    $conversation = apiwaySendConversation();
    $message = (new WhatsappApiwayHandler)->handleSendMessage($conversation, ['message' => 'oi']);

    expect($message->sender_type)->toBe(SenderType::Outgoing);

    (new WhatsappApiwayWebhookHandler)->handle($conversation->connection, [
        'type' => 'Receipt',
        'event' => ['MessageIDs' => ['3EB0AAA111'], 'Type' => '', 'Timestamp' => '2026-07-21T10:35:00-03:00'],
    ]);

    (new WhatsappApiwayWebhookHandler)->handle($conversation->connection, [
        'type' => 'Receipt',
        'event' => ['MessageIDs' => ['3EB0AAA111'], 'Type' => 'read', 'Timestamp' => '2026-07-21T10:36:00-03:00'],
    ]);

    $message->refresh();

    expect($message->delivery_at)->not->toBeNull()
        ->and($message->read_at)->not->toBeNull();
});
