<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Http\Resources\MessageResource;
use App\Models\ApiKey;
use App\Models\ApiwayQueuedSend;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\ApiwaySendQueue;
use App\Services\Message\Handlers\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const SEND_QUEUE_ID = 'cbb0de34-75fd-4475-b7c2-0e4589997671';

function sendQueueConversation(): Conversation
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
            'external_id' => '5511932951604',
            'name' => 'Cliente',
        ])->id,
        'connection_id' => $connection->id,
        'external_id' => '5511932951604',
        'status' => ConversationStatus::Active,
    ]);
}

/** The core's 202: accepted into the anti-ban queue, not sent. */
function queuedResponse(string $id = SEND_QUEUE_ID): array
{
    return ['messageId' => $id, 'status' => 'queued'];
}

function sendLogEntry(array $overrides = []): array
{
    return array_merge([
        'id' => SEND_QUEUE_ID,
        'kind' => 'send-text',
        'to' => '5511932951604',
        'status' => 'queued',
        'delivery' => 'queue',
        'priority' => false,
        'attempts' => 0,
        'created_at' => now()->utc()->toIso8601ZuluString(),
        'scheduled_at' => now()->utc()->toIso8601ZuluString(),
        'sent_at' => null,
        'error' => null,
    ], $overrides);
}

function queuedTextMessage(): Message
{
    Http::fake(['*/v1/message/send-text*' => Http::response(queuedResponse(), 202)]);

    return (new WhatsappApiwayHandler)->handleSendMessage(sendQueueConversation(), ['message' => 'Posso enviar o material?']);
}

function fakeSendLog(array $entries): void
{
    Http::fake([
        '*/v1/instance/send-log*' => Http::response([
            'success' => true,
            'data' => $entries,
            'next_cursor' => null,
        ]),
    ]);
}

test('a send the core only queued is marked as waiting, not sent', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);

    $message = queuedTextMessage();

    expect($message->fresh()->meta['send_queue'])->toMatchArray(['id' => SEND_QUEUE_ID, 'status' => 'queued'])
        ->and(ApiwayQueuedSend::where('message_id', $message->id)->value('queue_id'))->toBe(SEND_QUEUE_ID);

    $published = (new MessageResource($message->fresh()))->resolve()['meta']['send_queue'];

    expect($published)->toBe(['status' => 'queued', 'scheduled_at' => null, 'sent_at' => null, 'error_code' => null]);
});

test('a send the core made on the spot is not tracked', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    Http::fake([
        '*/v1/message/send-text*' => Http::response([
            'success' => true,
            'data' => ['code' => 200, 'data' => ['Details' => 'Sent', 'Id' => '3EB0AAA111', 'Timestamp' => 1786494578], 'success' => true],
        ]),
    ]);

    $message = (new WhatsappApiwayHandler)->handleSendMessage(sendQueueConversation(), ['message' => 'oi']);

    expect($message->fresh()->meta)->not->toHaveKey('send_queue')
        ->and(ApiwayQueuedSend::count())->toBe(0)
        ->and((new MessageResource($message->fresh()))->resolve()['meta'] ?? [])->not->toHaveKey('send_queue');
});

test('the sync marks a queued message sent once the core sent it', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    $message = queuedTextMessage();

    fakeSendLog([sendLogEntry(['status' => 'sent', 'attempts' => 1, 'sent_at' => '2026-10-24T00:02:13.688037Z'])]);

    $this->artisan('apiway:sync-send-queue')->assertSuccessful();

    $state = $message->fresh()->meta['send_queue'];

    expect($state['status'])->toBe('sent')
        ->and($state['sent_at'])->toBe('2026-10-24T00:02:13Z')
        ->and($message->fresh()->error)->toBeNull()
        ->and(ApiwayQueuedSend::count())->toBe(0);

    Event::assertDispatched(MessageUpdated::class, fn ($event) => $event->message->id === $message->id);
});

test('a queued message the core could not send says why, in our words', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    $message = queuedTextMessage();

    fakeSendLog([sendLogEntry([
        'status' => 'failed',
        'attempts' => 1,
        'error' => [
            'code' => 'reachout_timelock',
            'whatsapp_code' => 463,
            'message' => 'O WhatsApp recusou o envio (erro 463, restrição para iniciar conversas).',
        ],
    ])]);

    $this->artisan('apiway:sync-send-queue')->assertSuccessful();

    $fresh = $message->fresh();

    expect($fresh->meta['send_queue'])->toMatchArray(['status' => 'failed', 'error_code' => 'reachout_timelock'])
        ->and($fresh->error)->toStartWith('WhatsApp refused to start a conversation')
        ->and($fresh->error)->not->toContain('recusou')
        ->and(ApiwayQueuedSend::count())->toBe(0);
});

test('a core timeout is unconfirmed, not failed — it may have been delivered', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    $message = queuedTextMessage();

    fakeSendLog([sendLogEntry(['status' => 'failed', 'error' => ['code' => 'timeout', 'message' => 'pode ter sido entregue']])]);

    $this->artisan('apiway:sync-send-queue')->assertSuccessful();

    expect($message->fresh()->meta['send_queue']['status'])->toBe('unconfirmed')
        ->and($message->fresh()->error)->toBeNull();
});

test('a message held until tomorrow records when, and is not read again before then', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    Carbon::setTestNow('2026-10-23 12:00:00');
    $message = queuedTextMessage();

    fakeSendLog([sendLogEntry(['scheduled_at' => '2026-10-24T00:00:00Z'])]);

    $this->artisan('apiway:sync-send-queue')->assertSuccessful();

    expect($message->fresh()->meta['send_queue'])->toMatchArray(['status' => 'queued', 'scheduled_at' => '2026-10-24T00:00:00Z'])
        ->and(ApiwayQueuedSend::first()->check_after->toIso8601ZuluString())->toBe('2026-10-24T00:00:00Z')
        ->and((new MessageResource($message->fresh()))->resolve()['meta']['send_queue']['scheduled_at'])
        ->toBe(Carbon::parse('2026-10-24T00:00:00Z')->timestamp);

    Http::fake();
    $this->artisan('apiway:sync-send-queue')->assertSuccessful();
    Http::assertNothingSent();

    Carbon::setTestNow();
});

test('a message the send log never shows is given up on after three days', function () {
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    $message = queuedTextMessage();
    ApiwayQueuedSend::query()->update(['created_at' => now()->subDays(4)]);

    fakeSendLog([]);

    $this->artisan('apiway:sync-send-queue')->assertSuccessful();

    expect($message->fresh()->meta['send_queue']['status'])->toBe('unconfirmed')
        ->and(ApiwayQueuedSend::count())->toBe(0);
});

test('a public API send the core queued arrives in the inbox already marked', function () {
    config(['services.billing.enforce' => false]);
    Event::fake([MessageUpdated::class, MessageReceived::class]);
    $conversation = sendQueueConversation();
    [, $plain] = ApiKey::issue($conversation->connection->tenant, 'Hub');

    Http::fake(['*/v1/message/send-text*' => Http::response(queuedResponse(), 202)]);

    $this->withHeader('X-Api-Key', $plain)
        ->postJson('/api/v1/send-message', [
            'connection_id' => $conversation->connection->public_id,
            'phone' => '5511932951604',
            'message' => 'oi',
        ])
        ->assertSuccessful();

    $message = Message::where('external_id', SEND_QUEUE_ID)->firstOrFail();

    expect($message->meta['send_queue']['status'])->toBe('queued')
        ->and(ApiwayQueuedSend::where('message_id', $message->id)->exists())->toBeTrue();

    Event::assertDispatched(MessageReceived::class, fn ($event) => $event->message->id === $message->id
        && ($event->message->meta['send_queue']['status'] ?? null) === 'queued');
});

test('the queued id is only read off the core 202', function () {
    expect(ApiwaySendQueue::queuedId(['messageId' => 'abc', 'status' => 'queued']))->toBe('abc')
        ->and(ApiwaySendQueue::queuedId(['messageId' => 'abc']))->toBeNull()
        ->and(ApiwaySendQueue::queuedId(['status' => 'queued']))->toBeNull()
        ->and(ApiwaySendQueue::queuedId(['success' => true, 'data' => ['Id' => '3EB0']]))->toBeNull();
});
