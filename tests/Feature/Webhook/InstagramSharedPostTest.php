<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\MessageType;
use App\Http\Resources\MessageResource;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\InstagramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A post or reel shared into the DM is the poster's media, not the
 * conversation's, so nothing is mirrored: the bubble carries the caption, plus
 * a link to the post whenever Instagram hands one over.
 */

const IG_SHARE_ACCOUNT_ID = '17841400000000002';
const IG_SHARE_CUSTOMER_ID = '9876543210000002';

function igShareConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Instagram,
        'name' => 'Loja oficial',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'access_token' => 'ig-token',
            'instagram_account_id' => IG_SHARE_ACCOUNT_ID,
        ],
    ]);
}

/** An inbound message whose only content is the given attachments. */
function igSharePayload(array $attachments, string $mid): array
{
    return [
        'id' => IG_SHARE_ACCOUNT_ID,
        'messaging' => [[
            'sender' => ['id' => IG_SHARE_CUSTOMER_ID],
            'recipient' => ['id' => IG_SHARE_ACCOUNT_ID],
            'timestamp' => 1786621132000,
            'message' => ['mid' => $mid, 'attachments' => $attachments],
        ]],
    ];
}

/** Shape seen in production: `url` is already the public permalink. */
function igReelAttachment(string $caption = 'Se o seu domingo não é assim 🎾'): array
{
    return [
        'type' => 'ig_reel',
        'payload' => [
            'reel_video_id' => '18010020866949303',
            'title' => $caption,
            'url' => 'https://www.instagram.com/reel/DcI0Jd4oeTJ/',
        ],
    ];
}

/**
 * Shape seen in production for a feed post: a signed CDN mirror and a media id
 * the Graph API refuses for anyone else's media. No permalink anywhere.
 */
function igPostAttachment(string $type = 'ig_post', string $caption = 'Registros 📸❤️'): array
{
    return [
        'type' => $type,
        'payload' => [
            'ig_post_media_id' => '18076990211540716',
            'title' => $caption,
            'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=18076990211540716&signature=Ab1JMpB',
        ],
    ];
}

beforeEach(function () {
    Event::fake();
    Queue::fake();
    Http::preventStrayRequests();
});

test('a shared reel becomes the link to the post, and only that', function () {
    (new InstagramHandler)->handle(igShareConnection(), igSharePayload([igReelAttachment()], 'mid.reel.1'));

    $message = Message::sole();

    expect($message->message_type)->toBe(MessageType::InstagramShare)
        // The caption is the poster's copy, not the customer's — it stays in
        // meta for the bubble to use, out of the line the agent clicks.
        ->and($message->body)->toBe('https://www.instagram.com/reel/DcI0Jd4oeTJ/')
        ->and($message->attachment)->toBeNull()
        ->and($message->meta['instagram_share'])->toBe([
            'kind' => 'reel',
            'permalink' => 'https://www.instagram.com/reel/DcI0Jd4oeTJ/',
            'media_id' => '18010020866949303',
            'caption' => 'Se o seu domingo não é assim 🎾',
        ]);
});

test('a shared feed post falls back to its caption, because Instagram sends no link', function () {
    (new InstagramHandler)->handle(igShareConnection(), igSharePayload([igPostAttachment()], 'mid.post.1'));

    $message = Message::sole();

    expect($message->message_type)->toBe(MessageType::InstagramShare)
        // The lookaside url is a mirror of the media that expires with its
        // signature — pasting it would leave a 404 in the thread.
        ->and($message->body)->toBe('Registros 📸❤️')
        ->and($message->attachment)->toBeNull()
        ->and($message->meta['instagram_share'])->toBe([
            'kind' => 'post',
            'permalink' => null,
            'media_id' => '18076990211540716',
            'caption' => 'Registros 📸❤️',
        ]);
});

test('a share carries a body even when the post has no caption', function () {
    (new InstagramHandler)->handle(
        igShareConnection(),
        igSharePayload([igPostAttachment('share', '')], 'mid.post.2')
    );

    expect(Message::sole()->body)->toBe('Instagram post shared')
        ->and(Message::sole()->meta['instagram_share']['caption'])->toBeNull();
});

test('nothing is downloaded for a share', function () {
    // One webhook, the same post repeated as both `share` and `ig_post` — the
    // shape Instagram actually sends for a feed post.
    (new InstagramHandler)->handle(
        igShareConnection(),
        igSharePayload([igPostAttachment('share'), igPostAttachment()], 'mid.post.3')
    );

    Queue::assertNotPushed(DownloadInboundMedia::class);
});

test('the linkable attachment wins when a batch mixes shapes', function () {
    (new InstagramHandler)->handle(
        igShareConnection(),
        igSharePayload([igPostAttachment('share'), igReelAttachment()], 'mid.mixed.1')
    );

    expect(Message::sole()->body)->toBe('https://www.instagram.com/reel/DcI0Jd4oeTJ/');
});

test('the bubble reads the share off meta, and the rest of the webhook stays out of the SPA', function () {
    $connection = igShareConnection();
    (new InstagramHandler)->handle($connection, igSharePayload([igReelAttachment()], 'mid.reel.2'));

    $meta = (new MessageResource(Message::sole()->load('conversation.connection')))
        ->toArray(request())['meta'];

    expect($meta)->toHaveKey('instagram_share')
        ->and($meta['instagram_share']['permalink'])->toBe('https://www.instagram.com/reel/DcI0Jd4oeTJ/')
        ->and($meta)->not->toHaveKey('messaging');
});

test('a plain photo is still mirrored', function () {
    (new InstagramHandler)->handle(igShareConnection(), igSharePayload([[
        'type' => 'image',
        'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1&signature=x'],
    ]], 'mid.image.1'));

    expect(Message::sole()->message_type)->toBe(MessageType::Image);
    Queue::assertPushed(DownloadInboundMedia::class);
});
