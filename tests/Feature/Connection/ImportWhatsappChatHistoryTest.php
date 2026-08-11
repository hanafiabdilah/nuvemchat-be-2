<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Jobs\ImportWhatsappChatHistory;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Setting::set(ApiwayConfig::KEY_BASE_URL, 'https://core.test');
});

function importTenant(): Tenant
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

function historyImportConnection(Tenant $tenant, ?array $historyImport = null): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'API Way ' . uniqid(),
        'color' => '#22c55e',
        'status' => Status::Active,
        'credentials' => [
            'instance_id' => 'inst-123',
            'token' => 'instance-token',
            'import_history' => true,
            'history_import' => $historyImport ?? ['status' => 'queued'],
        ],
    ]);
}

function fakeFetchChats(array $body, int $status = 200): void
{
    Http::fake(['core.test/v1/chats/fetch-chats*' => Http::response($body, $status)]);
}

it('re-queues itself when the core has not indexed the chats yet', function () {
    Queue::fake();

    $connection = historyImportConnection(importTenant());

    // The shape seen one second after pairing: success, but no list.
    fakeFetchChats(['success' => true, 'data' => null]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    $state = $connection->fresh()->credentials['history_import'];

    expect($state['status'])->toBe('queued')
        ->and($state['attempt'])->toBe(2);

    Queue::assertPushed(ImportWhatsappChatHistory::class, fn ($job) => $job->attempt === 2);
});

it('gives up as retryable failed once the wait attempts run out', function () {
    Queue::fake();

    $connection = historyImportConnection(importTenant());

    fakeFetchChats(['success' => true, 'data' => null]);

    (new ImportWhatsappChatHistory($connection->id, ImportWhatsappChatHistory::MAX_READY_ATTEMPTS))->handle();

    $state = $connection->fresh()->credentials['history_import'];

    // "failed" is deliberate: a later reconnect re-queues the import.
    expect($state['status'])->toBe('failed')
        ->and($state['error'])->toContain('Chat list not ready');

    Queue::assertNotPushed(ImportWhatsappChatHistory::class);
});

it('still rejects a genuinely unknown payload without retrying', function () {
    Queue::fake();

    $connection = historyImportConnection(importTenant());

    fakeFetchChats(['unexpected' => ['nested' => true]]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    $state = $connection->fresh()->credentials['history_import'];

    expect($state['status'])->toBe('failed')
        ->and($state['error'])->toContain('Unexpected fetch-chats response shape');

    Queue::assertNotPushed(ImportWhatsappChatHistory::class);
});

it('treats an empty list as a clean run with nothing to import', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());

    fakeFetchChats(['success' => true, 'data' => []]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    $state = $connection->fresh()->credentials['history_import'];

    expect($state['status'])->toBe('done')
        ->and($state['imported'])->toBe(0);
});

it('imports the real API Way envelope and parses its ISO-8601 timestamp', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());

    $lastMessage = now()->subDays(2);

    fakeFetchChats(['success' => true, 'data' => [
        ['chatId' => '5511999998888@s.whatsapp.net', 'lastMessageTime' => $lastMessage->toIso8601ZuluString()],
        // Broadcast/status is not a real chat and must be skipped.
        ['chatId' => 'status@broadcast', 'lastMessageTime' => $lastMessage->toIso8601ZuluString()],
    ]]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    $state = $connection->fresh()->credentials['history_import'];

    expect($state['status'])->toBe('done')
        ->and($state['imported'])->toBe(1);

    $conversation = Conversation::where('connection_id', $connection->id)->sole();

    expect($conversation->external_id)->toBe('5511999998888')
        // The ISO string must survive as the real time, not "now".
        ->and($conversation->last_message_at->timestamp)->toBe($lastMessage->timestamp);
});

it('drops chats older than the age cutoff now that timestamps parse', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());

    fakeFetchChats(['success' => true, 'data' => [[
        'chatId' => '5511999997777@s.whatsapp.net',
        'lastMessageTime' => now()->subDays(ImportWhatsappChatHistory::MAX_AGE_DAYS + 5)->toIso8601ZuluString(),
    ]]]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    expect($connection->fresh()->credentials['history_import']['status'])->toBe('done')
        ->and(Conversation::where('connection_id', $connection->id)->count())->toBe(0);
});
