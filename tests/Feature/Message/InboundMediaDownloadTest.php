<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Events\MessageReceived;
use App\Events\MessageUpdated;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function mediaTestConnection(): Connection
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

function imageWebhookPayload(): array
{
    return [
        'id' => '1463349248861966',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'display_phone_number' => '555481061675',
                    'phone_number_id' => '1083508778182246',
                ],
                'contacts' => [[
                    'profile' => ['name' => 'Cliente'],
                    'wa_id' => '12134098546',
                ]],
                'messages' => [[
                    'from' => '12134098546',
                    'id' => 'wamid.MEDIA-1',
                    'timestamp' => '1785943076',
                    'type' => 'image',
                    'image' => [
                        'id' => '999888777',
                        'mime_type' => 'image/jpeg',
                        'caption' => 'olha isso',
                    ],
                ]],
            ],
        ]],
    ];
}

test('a media webhook stores and broadcasts the message without waiting for the file', function () {
    Event::fake();
    Queue::fake();
    Http::preventStrayRequests();

    $connection = mediaTestConnection();

    (new WhatsappOfficialHandler)->handle($connection, imageWebhookPayload());

    $message = Message::first();

    // The caption is on the wire immediately; the bytes are somebody else's
    // problem. Http::preventStrayRequests() is the real assertion here — the
    // webhook made no CDN call at all.
    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->body)->toBe('olha isso')
        ->and($message->attachment)->toBeNull()
        ->and($message->attachment_status)->toBe(AttachmentStatus::Pending);

    Event::assertDispatched(MessageReceived::class);
    Queue::assertPushed(DownloadInboundMedia::class);
});

test('the queued download stores the file and re-broadcasts the message', function () {
    Event::fake();
    Storage::fake('local');

    $connection = mediaTestConnection();

    Http::fake([
        'graph.facebook.com/v25.0/999888777' => Http::response(['url' => 'https://cdn.example/media.jpg']),
        'cdn.example/*' => Http::response('binary-bytes'),
    ]);

    // Sync queue: the job runs as part of the webhook, which is what the test
    // suite does anyway — the point is what it leaves behind.
    (new WhatsappOfficialHandler)->handle($connection, imageWebhookPayload());

    $message = Message::first()->fresh();

    expect($message->attachment)->not->toBeNull()
        ->and($message->attachment_status)->toBeNull()
        ->and(Storage::disk('local')->get($message->attachment))->toBe('binary-bytes');

    // The second broadcast is how an open dashboard swaps the placeholder for
    // the real image without a refresh.
    Event::assertDispatched(MessageUpdated::class);
});

test('media the channel never hands over is retried and then marked failed', function () {
    Event::fake();
    Storage::fake('local');
    Queue::fake();

    $connection = mediaTestConnection();
    (new WhatsappOfficialHandler)->handle($connection, imageWebhookPayload());

    $message = Message::first();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'gone']], 404),
    ]);

    // Throwing is deliberate: the handler swallows its own HTTP errors, so an
    // empty `attachment` is the only signal left that the attempt is worth
    // repeating. Without it a CDN blip would lose the file for good.
    expect(fn () => (new DownloadInboundMedia($message))->handle())
        ->toThrow(RuntimeException::class);

    expect($message->fresh()->attachment_status)->toBe(AttachmentStatus::Pending);

    // Attempts exhausted: the queue calls failed() last, and the bubble stops
    // spinning.
    (new DownloadInboundMedia($message))->failed(new RuntimeException('out of attempts'));

    expect($message->fresh()->attachment_status)->toBe(AttachmentStatus::Failed)
        ->and($message->fresh()->attachment)->toBeNull();

    Event::assertDispatched(MessageUpdated::class);
});

test('a download job for a message that already has its file is a no-op that still notifies', function () {
    Event::fake();
    Storage::fake('local');
    Http::preventStrayRequests();

    $connection = mediaTestConnection();
    Queue::fake();
    (new WhatsappOfficialHandler)->handle($connection, imageWebhookPayload());

    $message = Message::first();
    $message->forceFill(['attachment' => 'media/already-there.jpg'])->save();

    // A retry after a partial success must not re-download; preventStrayRequests
    // fails the test if it tries.
    (new DownloadInboundMedia($message))->handle();

    expect($message->fresh()->attachment_status)->toBeNull();
    Event::assertDispatched(MessageUpdated::class);
});
