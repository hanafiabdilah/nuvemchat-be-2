<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function apiwayTimestampConnection(): Connection
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
 * whatsmeow message event. `Timestamp` carries the host's UTC offset, which is
 * what used to be stored verbatim into a UTC column.
 */
function apiwayMessageEvent(string $timestamp, string $id, string $body): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => $id,
                'Chat' => '270514354389042@lid',
                'Sender' => '270514354389042@lid',
                'SenderAlt' => '6285899367071:12@s.whatsapp.net',
                'PushName' => 'mutiamuripa',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => $timestamp,
            ],
            'Message' => ['conversation' => $body],
        ],
    ];
}

test('a webhook timestamp with an offset is stored as the real instant, not the wall clock', function () {
    Event::fake();
    $connection = apiwayTimestampConnection();

    // 10:34:28 in UTC-3 is 13:34:28 UTC.
    (new WhatsappApiwayHandler)->handle(
        $connection,
        apiwayMessageEvent('2026-07-21T10:34:28-03:00', 'MSG-1', 'from phone')
    );

    // Assert the raw column values — that is what a DB client shows and what
    // ORDER BY sorts on. The model casts these to unix ints on read.
    $row = DB::table('messages')->where('external_id', 'MSG-1')->first();

    expect($row->sent_at)->toBe('2026-07-21 13:34:28')
        ->and($row->delivery_at)->toBe('2026-07-21 13:34:28');
});

test('phone and panel messages seconds apart stay seconds apart in the database', function () {
    Event::fake();
    $connection = apiwayTimestampConnection();

    // The panel writes now() in app time; the phone's webhook says the same
    // instant expressed as UTC-3. Before the fix these landed 3 hours apart and
    // the conversation rendered out of order.
    $panelSentAt = '2026-07-21 13:34:19';

    (new WhatsappApiwayHandler)->handle(
        $connection,
        apiwayMessageEvent('2026-07-21T10:34:28-03:00', 'MSG-PHONE', 'from phone')
    );

    $conversation = Conversation::firstOrFail();
    $conversation->messages()->create([
        'external_id' => 'MSG-PANEL',
        'sender_type' => SenderType::Outgoing,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'from panel',
        'sent_at' => $panelSentAt,
        'delivery_at' => $panelSentAt,
    ]);

    $ordered = $conversation->messages()->orderBy('sent_at')->pluck('body')->all();

    $phone = strtotime(DB::table('messages')->where('external_id', 'MSG-PHONE')->value('sent_at'));
    $panel = strtotime(DB::table('messages')->where('external_id', 'MSG-PANEL')->value('sent_at'));

    expect(abs($phone - $panel))->toBe(9)
        ->and($ordered)->toBe(['from panel', 'from phone']);
});

test('a receipt timestamp with an offset is normalised too', function () {
    Event::fake();
    $connection = apiwayTimestampConnection();

    $conversation = Conversation::create([
        'contact_id' => \App\Models\Contact::create([
            'tenant_id' => $connection->tenant_id,
            'name' => 'mutiamuripa',
            'external_id' => '6285899367071',
        ])->id,
        'connection_id' => $connection->id,
        'external_id' => '6285899367071',
        'status' => \App\Enums\Conversation\Status::Active,
    ]);

    $message = $conversation->messages()->create([
        'external_id' => 'MSG-OUT',
        'sender_type' => SenderType::Outgoing,
        'message_type' => \App\Enums\Message\MessageType::Text,
        'body' => 'from panel',
        'sent_at' => '2026-07-21 13:34:19',
    ]);

    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'Receipt',
        'event' => [
            'MessageIDs' => ['MSG-OUT'],
            'Type' => 'read',
            'Timestamp' => '2026-07-21T10:35:29-03:00',
        ],
    ]);

    expect(DB::table('messages')->where('external_id', 'MSG-OUT')->value('read_at'))
        ->toBe('2026-07-21 13:35:29');
});

test('a UTC timestamp is left untouched', function () {
    Event::fake();
    $connection = apiwayTimestampConnection();

    (new WhatsappApiwayHandler)->handle(
        $connection,
        apiwayMessageEvent('2026-07-21T13:34:28Z', 'MSG-UTC', 'already utc')
    );

    expect(DB::table('messages')->where('external_id', 'MSG-UTC')->value('sent_at'))
        ->toBe('2026-07-21 13:34:28');
});
