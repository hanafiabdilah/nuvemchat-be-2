<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Exceptions\UserFacingException;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\AiMediaNodes;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\MessageNodes;
use App\Services\Media\StickerImage;
use App\Services\Message\MessageService;
use App\Services\Message\StickerMedia;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ChannelRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use Telegram\Bot\HttpClients\HttpClientInterface;

/*
 * Stickers: a picture a channel draws as a sticker.
 *
 * ⚠️ Helpers are prefixed `sticker…` because Pest loads every test file into
 * one process — a shared name is a fatal redeclare.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Storage::fake('local');
    Storage::fake('public');
});

/** A transparent PNG of the given size with an opaque square in the middle. */
function stickerPng(int $width = 300, int $height = 200): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, (int) ($width / 4), (int) ($height / 4), (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 220, 30, 30));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A WebP that already is a WhatsApp sticker. */
function stickerReadyWebp(): string
{
    return StickerImage::toWebp(stickerPng(512, 512));
}

/** The header of an animated WebP — all the code ever reads of one. */
function stickerAnimatedWebp(): string
{
    $vp8x = 'VP8X'.pack('V', 10).chr(0x02 | 0x10).str_repeat("\0", 9);
    $body = 'WEBP'.$vp8x.'ANIM'.pack('V', 6).str_repeat("\0", 6);

    return 'RIFF'.pack('V', strlen($body)).$body;
}

function stickerConversation(Channel $channel, array $credentials, string $externalId = '5511999999999'): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Canal',
        'status' => ConnectionStatus::Active,
        'credentials' => $credentials,
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id, 'channel' => $channel, 'external_id' => $externalId,
        'name' => 'Ana', 'username' => $externalId,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id,
        'external_id' => $externalId, 'status' => ConversationStatus::Active,
    ]);
}

function stickerOfficial(): Conversation
{
    return stickerConversation(Channel::WhatsappOfficial, [
        'phone_number_id' => 'PN123', 'access_token' => 'wa-token', 'business_account_id' => '111',
    ]);
}

function stickerApiway(): Conversation
{
    return stickerConversation(Channel::WhatsappApiway, ['instance_id' => 'INST-1', 'token' => 'test-token']);
}

/** @return list<array<string, mixed>> */
function stickerPayloads(string $needle): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (ChannelRequest $request) => str_contains($request->url(), $needle))
        ->map(fn (ChannelRequest $request) => $request->data())
        ->values()
        ->all();
}

// ── The picture ──────────────────────────────────────────────────────────────

test('a picture becomes a 512 by 512 WebP that keeps its transparency', function () {
    $webp = StickerImage::toWebp(stickerPng(300, 200));

    expect(StickerImage::isWebp($webp))->toBeTrue()
        ->and(getimagesizefromstring($webp)[0])->toBe(512)
        ->and(getimagesizefromstring($webp)[1])->toBe(512)
        ->and(strlen($webp))->toBeLessThanOrEqual(StickerImage::STATIC_MAX_BYTES)
        ->and(StickerImage::isReady($webp))->toBeTrue();

    // The corner is outside the picture, which was letterboxed: it must still
    // be see-through, not the black a new canvas starts as.
    $decoded = imagecreatefromstring($webp);
    expect((imagecolorat($decoded, 0, 0) >> 24) & 0x7F)->toBe(127);
});

test('an animated WebP is passed through untouched, never flattened to one frame', function () {
    $animated = stickerAnimatedWebp();

    expect(StickerImage::isAnimated($animated))->toBeTrue()
        ->and(StickerImage::toWebp($animated))->toBe($animated)
        ->and(StickerImage::toPng($animated))->toBeNull();
});

test('something that is not a picture cannot become a sticker', function () {
    expect(StickerImage::toWebp('%PDF-1.4 not an image'))->toBeNull();
});

// ── The node ────────────────────────────────────────────────────────────────

test('a sticker bubble carries no words and needs its file', function () {
    $item = MessageNodes::normalizeItem(['message_type' => 'sticker', 'body' => 'legenda que sobrou']);

    expect($item['message_type'])->toBe('sticker')
        ->and($item['body'])->toBe('')
        ->and(MessageNodes::isSendable($item))->toBeFalse()
        ->and(MessageNodes::isSendable(MessageNodes::normalizeItem([
            'message_type' => 'sticker', 'attachment_url' => 'https://cdn.example.com/oi.webp',
        ])))->toBeTrue();
});

test('a Message node sends a sticker bubble as a WhatsApp sticker, between its other bubbles', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response(stickerReadyWebp(), 200, ['Content-Type' => 'image/webp']),
        'graph.facebook.com/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.1']]])
            ->push(['messages' => [['id' => 'wamid.2']]])
            ->push(['messages' => [['id' => 'wamid.3']]]),
    ]);

    $conversation = stickerOfficial();
    $conversation->update(['status' => ConversationStatus::Pending]);
    $connection = $conversation->connection;

    $flow = Flow::create(['tenant_id' => $connection->tenant_id, 'name' => 'Figurinha']);
    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $node = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['messages' => [
            ['message_type' => 'text', 'body' => 'Oi!', 'delay' => 0],
            ['message_type' => 'sticker', 'attachment_url' => 'https://cdn.example.com/oi.webp', 'delay' => 0],
            ['message_type' => 'text', 'body' => 'Como posso ajudar?', 'delay' => 0],
        ]],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $node->id, 'condition_value' => null]);
    $connection->update(['flow_id' => $flow->id]);

    (new FlowExecutor)->startFlow($conversation->fresh());

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->get();

    expect($sent->pluck('message_type')->all())->toBe([MessageType::Text, MessageType::Sticker, MessageType::Text])
        ->and($sent[1]->body)->toBeNull()
        ->and($sent[1]->sent_by_flow_id)->toBe($flow->id);

    $sticker = collect(stickerPayloads('/PN123/messages'))->firstWhere('type', 'sticker');
    expect($sticker['sticker'])->toBe(['link' => 'https://cdn.example.com/oi.webp']);
});

// ── WhatsApp Official ───────────────────────────────────────────────────────

test('a ready WebP goes to the Cloud API by link, with no copy stored', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response(stickerReadyWebp(), 200, ['Content-Type' => 'image/webp']),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.S']]]),
    ]);

    $message = (new MessageService)->sendSticker(stickerOfficial(), ['media_url' => 'https://cdn.example.com/oi.webp']);

    expect($message->message_type)->toBe(MessageType::Sticker)
        ->and($message->external_id)->toBe('wamid.S')
        ->and($message->attachment)->toBe('https://cdn.example.com/oi.webp');

    expect(stickerPayloads('/PN123/messages')[0])->toMatchArray([
        'type' => 'sticker',
        'sticker' => ['link' => 'https://cdn.example.com/oi.webp'],
    ]);
});

test('a PNG is made into a WebP, uploaded as image/webp and sent by id', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response(stickerPng(), 200, ['Content-Type' => 'image/png']),
        'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-1']),
        'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.P']]]),
    ]);

    $message = (new MessageService)->sendSticker(stickerOfficial(), ['media_url' => 'https://cdn.example.com/figura.png']);

    expect(stickerPayloads('/PN123/messages')[0])->toMatchArray(['type' => 'sticker', 'sticker' => ['id' => 'MEDIA-1']]);

    $upload = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->first(fn (ChannelRequest $request) => str_ends_with($request->url(), '/PN123/media'));
    expect(collect($upload->data())->firstWhere('name', 'type')['contents'])->toBe('image/webp');

    expect($message->attachment)->toEndWith('figura.webp');
    expect(StickerImage::isWebp(Storage::disk('local')->get($message->attachment)))->toBeTrue();
});

test('a file that is not a picture is refused with our own sentence, before Meta is asked', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);

    expect(fn () => (new MessageService)->sendSticker(stickerOfficial(), ['media_url' => 'https://cdn.example.com/a.pdf']))
        ->toThrow(UserFacingException::class, 'não pode ser enviado como figurinha');

    expect(stickerPayloads('graph.facebook.com'))->toBe([]);
});

// ── API Way ─────────────────────────────────────────────────────────────────

test('API Way sends a ready WebP to send-sticker by URL', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response(stickerReadyWebp(), 200, ['Content-Type' => 'image/webp']),
        '*/v1/message/send-sticker*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-S']]),
    ]);

    $message = (new MessageService)->sendSticker(stickerApiway(), ['media_url' => 'https://cdn.example.com/oi.webp']);

    expect($message->message_type)->toBe(MessageType::Sticker)
        ->and($message->external_id)->toBe('OUT-S');
    expect(stickerPayloads('/v1/message/send-sticker')[0])->toBe([
        'phone' => '5511999999999',
        'sticker' => 'https://cdn.example.com/oi.webp',
    ]);
});

test('API Way gets a PNG as a WebP data URI', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response(stickerPng(), 200, ['Content-Type' => 'image/png']),
        '*/v1/message/send-sticker*' => Http::response(['success' => true, 'data' => ['id' => 'OUT-P']]),
    ]);

    $message = (new MessageService)->sendSticker(stickerApiway(), ['media_url' => 'https://cdn.example.com/figura.png']);

    $payload = stickerPayloads('/v1/message/send-sticker')[0];
    expect($payload['sticker'])->toStartWith('data:image/webp;base64,')
        ->and(StickerImage::isReady(base64_decode(substr($payload['sticker'], strlen('data:image/webp;base64,')))))->toBeTrue()
        ->and($message->attachment)->toEndWith('.webp');
});

// ── Telegram ────────────────────────────────────────────────────────────────

test('Telegram receives sendSticker, never sendPhoto', function () {
    Http::fake(['cdn.example.com/*' => Http::response(stickerReadyWebp(), 200, ['Content-Type' => 'image/webp'])]);

    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([
        new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'ok' => true,
            'result' => ['message_id' => 91, 'date' => 1793621514, 'chat' => ['id' => 4242, 'type' => 'private']],
        ])),
    ]));
    $stack->push(Middleware::history($history));
    app()->instance(HttpClientInterface::class, new GuzzleHttpClient(new Client(['handler' => $stack])));

    $conversation = stickerConversation(Channel::Telegram, ['token' => '123456:TEST-TOKEN'], '4242');
    $message = (new MessageService)->sendSticker($conversation, ['media_url' => 'https://cdn.example.com/oi.webp']);

    expect($message->message_type)->toBe(MessageType::Sticker)
        ->and((string) $message->external_id)->toBe('91');
    expect($history[0]['request']->getUri()->getPath())->toEndWith('/sendSticker');
});

// ── Channels with no stickers ───────────────────────────────────────────────

test('a channel with no stickers gets the same picture as an image', function () {
    Http::fake();

    $conversation = stickerConversation(Channel::LiveChatWidget, ['app_id' => 'app-sticker'], 'visitor-1');
    $message = (new MessageService)->sendSticker($conversation, ['media_url' => 'https://cdn.example.com/oi.webp']);

    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->body)->toBeNull();
});

test('Instagram and Messenger are sent a PNG, and an animated sticker is refused there', function () {
    Http::fake(['cdn.example.com/oi.webp' => Http::response(stickerReadyWebp(), 200, ['Content-Type' => 'image/webp'])]);

    $data = StickerMedia::asImageData(['media_url' => 'https://cdn.example.com/oi.webp'], Channel::Instagram);

    expect($data['image'])->toBeInstanceOf(UploadedFile::class)
        ->and($data['image']->getClientOriginalName())->toBe('oi.png')
        ->and(file_get_contents($data['image']->getRealPath()))->toStartWith("\x89PNG")
        ->and($data['message'])->toBeNull();

    Http::fake(['cdn.example.com/*' => Http::response(stickerAnimatedWebp(), 200, ['Content-Type' => 'image/webp'])]);

    expect(fn () => StickerMedia::asImageData(['media_url' => 'https://cdn.example.com/anim.webp'], Channel::Messenger))
        ->toThrow(UserFacingException::class, 'animadas');
});

// ── The upload ──────────────────────────────────────────────────────────────

test('a picture uploaded for a sticker is stored as the finished WebP', function () {
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $file = UploadedFile::fake()->createWithContent('figura.png', stickerPng());

    $response = $this->actingAs($user)->post('/api/uploads', [
        'file' => $file,
        'purpose' => 'flow',
        'sticker' => '1',
    ], ['Accept' => 'application/json'])->assertOk();

    expect($response->json('filename'))->toBe('figura.webp');

    $stored = Storage::disk('public')->get($response->json('path'));
    expect(StickerImage::isReady($stored))->toBeTrue();
});

// ── AI media ────────────────────────────────────────────────────────────────

test('only a picture from the AI media node can go out as a sticker', function () {
    expect(AiMediaNodes::sendsAsSticker(['media_type' => 'image', 'as_sticker' => true]))->toBeTrue()
        ->and(AiMediaNodes::sendsAsSticker(['media_type' => 'video', 'as_sticker' => true]))->toBeFalse()
        ->and(AiMediaNodes::sendsAsSticker(['media_type' => 'image']))->toBeFalse();
});
