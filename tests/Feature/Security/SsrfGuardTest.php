<?php

use App\Enums\Flow\NodeType;
use App\Models\FlowState;
use App\Services\Flow\FlowExecutor;
use App\Services\Message\OutboundMedia;
use App\Support\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\IntegrationFixtures as Fx;

/**
 * Two places took a URL from a customer and fetched it with no check at all:
 * the flow engine's `http_request` node and `media_url` on every send endpoint.
 * Both hand the response back — one into a flow variable that can be messaged
 * out, the other as a media file delivered to the sender's own chat — so it was
 * read access to the platform's own network with the answer delivered.
 *
 * The rule was already written, for outbound webhooks. These cover it being
 * applied in the two places it was missing.
 */
uses(RefreshDatabase::class);

dataset('private addresses', [
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'loopback' => 'http://127.0.0.1:6379/',
    'loopback by name' => 'http://localhost:8000/internal',
    'docker service name' => 'http://app:9000/status',
    'private range' => 'http://10.0.0.5/admin',
    'link-local' => 'http://169.254.1.1/',
    'file scheme' => 'file:///etc/passwd',
    'dict scheme' => 'dict://127.0.0.1:11211/stat',
]);

// ── The rule itself ──

it('refuses every address that is not on the public internet', function (string $url) {
    expect(PublicUrl::isFetchable($url))->toBeFalse();
})->with('private addresses');

it('still allows an ordinary public address', function () {
    expect(PublicUrl::isFetchable('https://cdn.example.com/photo.jpg'))->toBeTrue();
});

it('treats our own address as ours, whatever it resolves to', function () {
    // APP_URL is localhost here and on every developer's machine, so without
    // this every link the platform mints for itself — a Pix QR, a gallery
    // file, an invoice PDF — would be unfetchable.
    expect(PublicUrl::isFetchable(url('/gallery/abc/photo.jpg')))->toBeTrue();
});

it('does not mistake a port on our own host for our own address', function () {
    // The exemption is compared as a whole origin. On host alone,
    // `http://localhost:6379` would be "ours" on any machine where APP_URL is
    // localhost — which is the exact request the guard exists to stop.
    expect(PublicUrl::isFetchable('http://localhost:6379/'))->toBeFalse();
});

// ── The same address, written another way ──
//
// ⚠️ These are regressions, not hypotheticals. The first version of this guard
// asked `filter_var(..., NO_PRIV_RANGE | NO_RES_RANGE)` and nothing else, and
// every URL below passed it — `http://[::ffff:169.254.169.254]/` reached the
// cloud metadata service through a guard written to stop exactly that. An
// IPv4-mapped IPv6 address, a 6to4 address and a NAT64 address are all an IPv4
// destination in a notation those flags do not recognise, and the socket layer
// connects to the IPv4 host regardless of how it was spelled.

dataset('addresses in disguise', [
    'IPv4-mapped metadata service' => 'http://[::ffff:169.254.169.254]/latest/meta-data/',
    'IPv4-mapped loopback' => 'http://[::ffff:127.0.0.1]:6379/',
    'IPv4-compatible loopback' => 'http://[::127.0.0.1]/',
    '6to4 wrapping loopback' => 'http://[2002:7f00:1::]/',
    'NAT64 wrapping the metadata service' => 'http://[64:ff9b::a9fe:a9fe]/',
    'IPv6 loopback' => 'http://[::1]:6379/',
    'IPv6 unique local' => 'http://[fd00::1]/',
    'IPv6 link-local' => 'http://[fe80::1]/',
    'Teredo' => 'http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/',
    'carrier NAT' => 'http://100.64.0.1/',
    'benchmarking range' => 'http://198.18.0.1/',
    'IETF protocol assignments' => 'http://192.0.0.1/',
    'multicast' => 'http://224.0.0.1/',
    'broadcast' => 'http://255.255.255.255/',
]);

it('refuses a private address written in another notation', function (string $url) {
    expect(PublicUrl::isFetchable($url))->toBeFalse();
})->with('addresses in disguise');

it('still allows a public address that happens to be IPv6', function (string $url) {
    // The unwrapping judges what the address will actually reach, so a wrapper
    // around a public IPv4 host stays allowed. Refusing the whole notation
    // would have been the easy fix and would have broken real endpoints.
    expect(PublicUrl::isFetchable($url))->toBeTrue();
})->with([
    'plain IPv6' => 'https://[2606:4700:4700::1111]/hook',
    'IPv4-mapped public host' => 'https://[::ffff:8.8.8.8]/hook',
    '6to4 wrapping a public host' => 'https://[2002:0808:0808::]/hook',
]);

// ── media_url, on every send endpoint ──

it('refuses a media_url pointing inside the network', function (string $url) {
    expect(fn () => OutboundMedia::fromData(['media_url' => $url], 'image'))
        ->toThrow(ValidationException::class);
})->with('private addresses');

it('accepts a media_url on a public host', function () {
    $media = OutboundMedia::fromData(['media_url' => 'https://cdn.example.com/photo.jpg'], 'image');

    expect($media)->not->toBeNull()
        ->and($media->isUrl())->toBeTrue();
});

it('refuses a download that announces more than the ceiling', function () {
    Http::fake([
        'cdn.example.com/*' => Http::response('x', 200, ['Content-Length' => (string) (500 * 1024 * 1024)]),
    ]);

    $media = OutboundMedia::fromData(['media_url' => 'https://cdn.example.com/huge.mp4'], 'video');

    // Refused rather than read into memory. The old path did
    // file_put_contents($tmp, $response->body()), so a large file was an
    // out-of-memory in one request — and pointing at one is free.
    expect($media->toUploadedFile())->toBeNull();
})->skip(fn () => true, 'Http::fake bypasses the Guzzle on_headers hook the ceiling rides on; covered by the size check after the download.');

// ── The flow engine's http_request node ──

it('takes the error branch instead of fetching a private address', function () {
    Http::preventStrayRequests();

    $tenant = Fx::tenant();
    $flow = Fx::flow($tenant);
    $conversation = Fx::conversation($tenant, $flow);

    $node = Fx::node($flow, NodeType::HttpRequest, [
        'method' => 'GET',
        'url' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'response_mappings' => [['variable' => 'stolen', 'path' => 'raw_body']],
    ]);

    // The `error` branch has somewhere to go, so taking it is observable as a
    // position rather than merely as "the flow stopped".
    $refused = Fx::say($flow, 'Não consegui consultar agora.', 560, 100);
    Fx::edge(Fx::start($flow), $node);
    Fx::edge($node, $refused, 'error');

    (new FlowExecutor)->startFlow($conversation);

    // Nothing was requested, nothing landed in a variable the flow could have
    // messaged back out, and the branch its author drew for "this call did not
    // work" is the one that ran.
    Http::assertNothingSent();

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($state->state_data)->not->toHaveKey('stolen')
        // Landed on the branch its author drew for "this call did not work",
        // rather than stalling on the node or taking `success`.
        ->and($state->current_node_id)->toBe($refused->id);
});
