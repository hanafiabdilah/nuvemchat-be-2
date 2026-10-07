<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Jobs\ImportWhatsappChatHistory;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Enums\Message\SenderType;
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

/**
 * One static fake for both endpoints: Http::fake stacks callbacks and uses the
 * first answer, so a second fake() call in a test would never be reached.
 *
 * @param  array<string, array>  $messagesByChat  rows per chatId asked for
 */
function fakeFetchChats(array $body, int $status = 200, array $messagesByChat = []): void
{
    Http::fake(function ($request) use ($body, $status, $messagesByChat) {
        if (str_contains($request->url(), '/v1/chats/fetch-messages')) {
            return Http::response(['success' => true, 'data' => $messagesByChat[$request['chatId']] ?? []]);
        }

        return Http::response($body, $status);
    });
}

function historyRow(string $id, string $text, string $at, bool $fromMe = false, string $type = 'text'): array
{
    return [
        'message_id' => $id,
        'chat_jid' => '102701234567890@lid',
        'sender_jid' => '102701234567890@lid',
        'timestamp' => $at,
        'message_type' => $type,
        'text_content' => $text,
        'media_link' => '',
        'data_json' => json_encode(['Info' => ['ID' => $id, 'IsFromMe' => $fromMe]]),
    ];
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

it('imports a chat the core files under its @lid, keyed by the number', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());

    fakeFetchChats(['success' => true, 'data' => [
        [
            'chatId' => '102701234567890@lid',
            'jid' => '5511999999999@s.whatsapp.net',
            'phone' => '5511999999999',
            'name' => 'Ana Souza',
            'lastMessageTime' => now()->subDays(40)->format('Y-m-d\TH:i:sP'),
        ],
        // Known only by its privacy id: no number to key a contact by.
        ['chatId' => '999999999999999@lid', 'lastMessageTime' => now()->subDay()->toIso8601String()],
        ['chatId' => '120363000000000@g.us', 'name' => 'Grupo X', 'lastMessageTime' => now()->subDay()->toIso8601String()],
        ['chatId' => '0@s.whatsapp.net', 'lastMessageTime' => now()->subDay()->toIso8601String()],
    ]]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    expect($connection->fresh()->credentials['history_import']['imported'])->toBe(1);

    $conversation = Conversation::where('connection_id', $connection->id)->sole();
    $contact = Contact::findOrFail($conversation->contact_id);

    expect($conversation->external_id)->toBe('5511999999999')
        ->and($contact->external_id)->toBe('5511999999999')
        ->and($contact->name)->toBe('Ana Souza')
        ->and($contact->lid)->toBe('102701234567890@lid');
});

it('imports the real messages, oldest first, instead of a placeholder', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());
    $jid = '5511999999999@s.whatsapp.net';

    fakeFetchChats(
        ['success' => true, 'data' => [
            ['chatId' => '102701234567890@lid', 'jid' => $jid, 'lastMessageTime' => '2026-09-30T08:55:00-03:00'],
        ]],
        messagesByChat: [$jid => [
            // Newest first, as the core answers.
            historyRow('MSG-3', ':image:', '2026-09-30T08:55:00-03:00', type: 'image'),
            historyRow('MSG-2', 'Tudo sim, e você?', '2026-09-30T08:50:00-03:00', fromMe: true),
            historyRow('MSG-1', 'Oi, tudo bem?', '2026-09-30T08:49:45-03:00'),
            // Sent through the API: no stored event, and still ours.
            [...historyRow('MSG-0', 'Olá!', '2026-09-30T08:00:00-03:00'), 'data_json' => '', 'sender_jid' => 'me'],
            // Nothing to show.
            historyRow('MSG-R', '', '2026-09-30T08:51:00-03:00', type: 'reaction'),
        ]],
    );

    \Illuminate\Support\Carbon::setTestNow('2026-10-07 12:00:00');
    (new ImportWhatsappChatHistory($connection->id))->handle();

    $conversation = Conversation::where('connection_id', $connection->id)->sole();
    $messages = $conversation->messages()->orderBy('id')->get();

    expect($messages->pluck('external_id')->all())->toBe(['MSG-0', 'MSG-1', 'MSG-2', 'MSG-3'])
        ->and($messages->pluck('body')->all())->toBe(['Olá!', 'Oi, tudo bem?', 'Tudo sim, e você?', '[Imagem]'])
        ->and($messages->pluck('sender_type')->all())->toBe([
            SenderType::Outgoing, SenderType::Incoming, SenderType::Outgoing, SenderType::Incoming,
        ])
        // The offset is honoured: 08:49:45 -03:00 is 11:49:45 UTC.
        ->and(\Illuminate\Support\Carbon::parse($messages[1]->sent_at)->toIso8601ZuluString())->toBe('2026-09-30T11:49:45Z')
        ->and($messages->where('sender_type', SenderType::Incoming)->whereNull('read_at'))->toBeEmpty()
        ->and(\Illuminate\Support\Carbon::parse($conversation->last_message_at)->toIso8601ZuluString())->toBe('2026-09-30T11:55:00Z');
});

it('falls back to one placeholder line when the core holds no messages', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());

    fakeFetchChats(['success' => true, 'data' => [
        ['jid' => '5511999999999@s.whatsapp.net', 'lastMessageTime' => now()->subDay()->toIso8601String()],
    ]]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    $message = Message::sole();

    expect($message->body)->toContain('Conversa importada');
});

it('never stores a message twice, and a rerun skips what it already imported', function () {
    Bus::fake();

    $connection = historyImportConnection(importTenant());
    $first = '5511999999999@s.whatsapp.net';
    $second = '5511988887777@s.whatsapp.net';
    $at = now()->subDay()->toIso8601String();

    fakeFetchChats(
        ['success' => true, 'data' => [
            ['jid' => $first, 'lastMessageTime' => $at],
            ['jid' => $second, 'lastMessageTime' => $at],
        ]],
        messagesByChat: [
            $first => [historyRow('MSG-1', 'Oi', $at)],
            // Already delivered by the live webhook, to another conversation.
            $second => [historyRow('LIVE-1', 'Já chegou', $at)],
        ],
    );

    $other = Contact::create([
        'tenant_id' => $connection->tenant_id, 'external_id' => '551188887777',
        'channel' => $connection->channel, 'name' => 'Sem o nono dígito',
    ]);
    $live = Conversation::create([
        'contact_id' => $other->id, 'connection_id' => $connection->id,
        'external_id' => '551188887777', 'status' => \App\Enums\Conversation\Status::Pending,
    ]);
    $live->messages()->create([
        'external_id' => 'LIVE-1', 'sender_type' => SenderType::Incoming,
        'message_type' => \App\Enums\Message\MessageType::Text, 'body' => 'Já chegou', 'sent_at' => now(),
    ]);

    (new ImportWhatsappChatHistory($connection->id))->handle();

    expect($connection->fresh()->credentials['history_import']['imported'])->toBe(1)
        ->and(Message::where('external_id', 'LIVE-1')->count())->toBe(1);

    expect(ImportWhatsappChatHistory::rerun($connection->fresh()))->toBeTrue();
    (new ImportWhatsappChatHistory($connection->id))->handle();

    expect($connection->fresh()->credentials['history_import']['imported'])->toBe(0)
        ->and(Conversation::where('connection_id', $connection->id)->count())->toBe(2)
        ->and(Message::where('external_id', 'MSG-1')->count())->toBe(1);
});

it('waits after pairing before the first run, and not at all on a rerun', function () {
    Queue::fake();

    $connection = historyImportConnection(importTenant(), ['status' => 'failed']);

    ImportWhatsappChatHistory::dispatchIfPending($connection);
    Queue::assertPushed(ImportWhatsappChatHistory::class, fn ($job) => $job->delay !== null);

    // Already queued: a second request must not start a second run.
    expect(ImportWhatsappChatHistory::rerun($connection->fresh()))->toBeFalse();
});

it('reruns the import from the dashboard', function () {
    Queue::fake();
    $this->withoutMiddleware();

    $tenant = importTenant();
    $connection = historyImportConnection($tenant, ['status' => 'done', 'imported' => 0, 'skipped' => 0]);

    $this->actingAs(User::findOrFail($tenant->user_id))
        ->postJson("/api/connections/{$connection->id}/history-import/rerun")
        ->assertOk();

    expect($connection->fresh()->credentials['history_import']['status'])->toBe('queued');
    Queue::assertPushed(ImportWhatsappChatHistory::class, fn ($job) => $job->delay === null);

    $this->postJson("/api/connections/{$connection->id}/history-import/rerun")->assertStatus(409);
});
