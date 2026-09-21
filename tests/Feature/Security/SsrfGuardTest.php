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
