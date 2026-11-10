<?php

use App\Models\Integration;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

/**
 * The token Events Manager generates under Conversions API — the one the form
 * asks for — may send events but may not read the pixel. Meta's answers below
 * are the ones it gave on 10 Nov 2026, word for word.
 */
const META_PIXEL_READ_REFUSED = [
    'error' => [
        'message' => '(#100) Missing Permission',
        'type' => 'OAuthException',
        'code' => 100,
        'fbtrace_id' => 'AbCdEf',
    ],
];

const META_PIXEL_UNREACHABLE = [
    'error' => [
        'message' => "Unsupported post request. Object with ID '123456789012345' does not exist, cannot be loaded due to missing permissions, or does not support this operation.",
        'type' => 'GraphMethodException',
        'code' => 100,
        'error_subcode' => 33,
    ],
];

/** What Meta answers an event dated past its window, once the token may write. */
const META_PIXEL_EVENT_TOO_OLD = [
    'error' => [
        'message' => 'Invalid parameter',
        'type' => 'OAuthException',
        'code' => 100,
        'error_subcode' => 2804003,
        'error_user_title' => 'Event Timestamp Too Old',
    ],
];

/**
 * @param  array<string, mixed>  $read
 * @param  array<string, mixed>  $write
 */
function metaPixelFake(array $read, int $readStatus, array $write = [], int $writeStatus = 400): void
{
    Http::fake([
        'graph.facebook.com/*/events' => Http::response($write, $writeStatus),
        'graph.facebook.com/*' => Http::response($read, $readStatus),
    ]);
}

/** @param  array<string, mixed>  $settings */
function connectMetaPixel(array $settings = []): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'meta_pixel',
        'name' => 'Pixel da loja',
        'credentials' => Fx::CREDENTIALS['meta_pixel'],
        'settings' => array_merge(Fx::SETTINGS['meta_pixel'], $settings),
    ]);
}

function sentToMetaEvents(Request $request): bool
{
    return $request->method() === 'POST' && str_ends_with($request->url(), '/123456789012345/events');
}

test('a send-only Events Manager token connects, proven by an event Meta is certain to discard', function () {
    metaPixelFake(META_PIXEL_READ_REFUSED, 400, META_PIXEL_EVENT_TOO_OLD);

    connectMetaPixel()
        ->assertCreated()
        ->assertJsonPath('data.account.access', 'send_only')
        ->assertJsonPath('data.account.environment', 'production')
        ->assertJsonMissingPath('data.account.account_name');

    // Outside every window Meta accepts, so it can never count as a conversion.
    Http::assertSent(fn (Request $request) => sentToMetaEvents($request)
        && count($request['data']) === 1
        && $request['data'][0]['event_name'] === 'PinglyAccessCheck'
        && $request['data'][0]['event_time'] < now()->subDays(62)->getTimestamp()
        && ! isset($request['test_event_code']));

    expect(Integration::sole()->verified_at)->not->toBeNull();
});

test('the access check carries the test event code when one is set', function () {
    metaPixelFake(META_PIXEL_READ_REFUSED, 400, META_PIXEL_EVENT_TOO_OLD);

    connectMetaPixel(['test_event_code' => 'TEST12345'])
        ->assertCreated()
        ->assertJsonPath('data.account.environment', 'test');

    Http::assertSent(fn (Request $request) => sentToMetaEvents($request) && $request['test_event_code'] === 'TEST12345');
});

test('a token that cannot write to the pixel is still refused, and nothing is stored', function () {
    metaPixelFake(META_PIXEL_READ_REFUSED, 400, META_PIXEL_UNREACHABLE);

    connectMetaPixel()
        ->assertUnprocessable()
        ->assertJsonPath('code', 'pixel_not_found')
        ->assertJsonMissing(['message' => '(#100) Missing Permission']);

    expect(Integration::count())->toBe(0);
});

test('a write refused for permission says so, instead of blaming a pixel node', function () {
    metaPixelFake(META_PIXEL_READ_REFUSED, 400, META_PIXEL_READ_REFUSED);

    connectMetaPixel()
        ->assertUnprocessable()
        ->assertJsonPath('code', 'pixel_permission_missing');

    expect(Integration::count())->toBe(0);
});

test('a token that can read the pixel keeps its name and sends nothing', function () {
    metaPixelFake(['id' => '123456789012345', 'name' => 'Pixel Loja Aurora'], 200);

    connectMetaPixel()
        ->assertCreated()
        ->assertJsonPath('data.account.account_name', 'Pixel Loja Aurora')
        ->assertJsonMissingPath('data.account.access');

    Http::assertNotSent(fn (Request $request) => sentToMetaEvents($request));
});

test('an expired token is refused without trying to send', function () {
    metaPixelFake([
        'error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190],
    ], 400);

    connectMetaPixel()
        ->assertUnprocessable()
        ->assertJsonPath('code', 'integration_credentials_invalid');

    Http::assertNotSent(fn (Request $request) => sentToMetaEvents($request));
});

test('a lone Missing Permission is a permission problem, never a rejected event', function () {
    $e = UpstreamError::exception(UpstreamProvider::MetaPixel, '(#100) Missing Permission', '100', 400);

    expect($e->toResponse()->getData(true)['code'])->toBe('pixel_permission_missing');
});
