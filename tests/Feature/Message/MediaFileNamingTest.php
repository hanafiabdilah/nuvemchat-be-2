<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A file's name, from the channel to the agent's disk and back out again.
 *
 * MediaFilenameTest covers the naming rule in isolation. This covers the part
 * that made it worth fixing: the stored path is what the document bubble prints
 * and what "save file" writes, so a hashed path meant an agent handling a
 * dispute had `4812_68d1a2f3b4c5d.pdf` where the customer had
 * `Comprovante de Pagamento.pdf`.
 *
 * Two names, and they now usually agree: `meta.filename` is the pristine one and
 * exists only where the channel reported it, while the path's last segment is
 * the one every row has. Neither carries a code — uniqueness lives in the
 * directory above — and neither is mangled, because the URL layer encodes
 * instead. They still differ where a path cannot hold a character at all, so the
 * SPA prefers the first and falls back to the second.
 */
uses(RefreshDatabase::class);

function namingConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['access_token' => 'test-token', 'phone_number_id' => 'PN777'],
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function namingConversation(Connection $connection): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'name' => 'Maria',
        'external_id' => '5511999998888',
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999998888',
        'status' => ConversationStatus::Active,
        'user_id' => $connection->tenant->user_id,
    ]);
}

function namingDocumentWebhook(?string $filename): array
{
    return [
        'id' => 'ENTRY-1',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '5511777776666', 'phone_number_id' => 'PN777'],
                'contacts' => [['profile' => ['name' => 'Maria'], 'wa_id' => '5511999998888']],
                'messages' => [[
                    'from' => '5511999998888',
                    'id' => 'wamid.DOC-1',
                    'timestamp' => '1785943076',
                    'type' => 'document',
                    'document' => array_filter([
                        'id' => '555444333',
                        'mime_type' => 'application/pdf',
                        'filename' => $filename,
                    ]),
                ]],
            ],
        ]],
    ];
}

function namingFakeCdn(): void
{
    Http::fake([
        'graph.facebook.com/v25.0/555444333' => Http::response(['url' => 'https://cdn.example.com/doc']),
        'cdn.example.com/*' => Http::response('pdf-bytes'),
    ]);
}

// ── Inbound ──

test('a document the customer sent is stored under the name they gave it', function () {
    Event::fake();
    Storage::fake('local');
    namingFakeCdn();

    $connection = namingConnection();

    (new App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler)
        ->handle($connection, namingDocumentWebhook('Comprovante de Pagamento.pdf'));

    $message = Message::first()->fresh();

    // The path: the customer's own name, spelling and all. What makes it unique
    // is the directory, which nobody reads.
    expect($message->attachment)->toBe("media/{$message->id}/Comprovante de Pagamento.pdf")
        // The pristine copy, which is what the agent actually reads.
        ->and($message->meta['filename'])->toBe('Comprovante de Pagamento.pdf')
        ->and(Storage::disk('local')->get($message->attachment))->toBe('pdf-bytes');
});

test('the pristine name reaches the dashboard', function () {
    Event::fake();
    Storage::fake('local');
    namingFakeCdn();

    $connection = namingConnection();

    (new App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler)
        ->handle($connection, namingDocumentWebhook('Relatório Anual.pdf'));

    $message = Message::first()->fresh()->load('conversation.connection');

    // Merged outside the per-channel match, like a voice note's transcription:
    // a file's name belongs to the file, not to the channel that carried it.
    $payload = (new MessageResource($message))->resolve();

    // Both names now agree, because the path no longer has to give anything up:
    // the URL layer encodes, so the stored name does not have to be mangled.
    expect($payload['meta']['filename'])->toBe('Relatório Anual.pdf')
        ->and(basename($message->attachment))->toBe('Relatório Anual.pdf');
});

test('a filename carrying a path cannot choose where the download lands', function () {
    Event::fake();
    Storage::fake('local');
    namingFakeCdn();

    $connection = namingConnection();

    (new App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler)
        ->handle($connection, namingDocumentWebhook('../../../../etc/cron.d/evil.pdf'));

    $message = Message::first()->fresh()->load('conversation.connection');
    $payload = (new MessageResource($message))->resolve();

    expect($message->attachment)->toStartWith('media/')
        ->and($message->attachment)->not->toContain('..')
        // The resource basenames it too: this string reaches a `download`
        // attribute in the browser.
        ->and($payload['meta']['filename'])->toBe('evil.pdf');
});

test('a channel that reports no name falls back to what the file is', function () {
    Event::fake();
    Storage::fake('local');
    namingFakeCdn();

    $connection = namingConnection();

    // WhatsApp sends `filename` for documents only. Nothing else has a name on
    // either side, so the message type is the most the path can honestly say.
    (new App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler)
        ->handle($connection, namingDocumentWebhook(null));

    $message = Message::first()->fresh();

    expect(basename($message->attachment))->toBe('document.pdf')
        ->and($message->attachment)->toBe("media/{$message->id}/document.pdf")
        ->and($message->meta)->not->toHaveKey('filename');
});

// ── Outbound ──

test('a document an agent sends is stored under its own name', function () {
    Storage::fake('local');

    Http::fake([
        '*/PN777/media' => Http::response(['id' => 'MEDIA_OUT']),
        '*/PN777/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
    ]);

    $connection = namingConnection();
    $conversation = namingConversation($connection);

    $file = UploadedFile::fake()->create('Proposta Comercial.pdf', 4, 'application/pdf');
    file_put_contents($file->getRealPath(), 'proposal-bytes');

    $message = app(MessageService::class)->sendDocument($conversation, ['document' => $file]);

    expect($message->fresh()->attachment)->toBe("media/{$message->id}/Proposta Comercial.pdf")
        // Already recorded by this handler before any of this; it is what the
        // customer's WhatsApp shows, and now the agent's bubble agrees with it.
        ->and($message->meta['filename'])->toBe('Proposta Comercial.pdf');
});
