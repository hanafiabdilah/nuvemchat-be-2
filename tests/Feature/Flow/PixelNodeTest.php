<?php

use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Models\Conversation;
use App\Models\Integration;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

/**
 * start → pixel → "Obrigado!".
 *
 * The trailing message is the whole point of several tests here: tracking
 * must never be the reason a customer's next message does not arrive.
 *
 * @param  array<string, mixed>  $data
 * @param  list<Integration>  $integrations
 */
function pixelNodeFixture(array $data, callable $integrations): Conversation
{
    $tenant = Fx::tenant();
    $flow = Fx::flow($tenant);

    $ids = array_map(fn (Integration $integration) => $integration->id, $integrations($tenant));

    $pixel = Fx::node($flow, NodeType::Pixel, array_merge(['integration_ids' => $ids, 'event' => 'lead'], $data));
    $after = Fx::say($flow, 'Obrigado!');

    Fx::edge(Fx::start($flow), $pixel);
    Fx::edge($pixel, $after);

    return Fx::conversation($tenant, $flow);
}

function pixelNodeFake(array $meta = ['events_received' => 1], int $metaStatus = 200): void
{
    Http::fake([
        'graph.facebook.com/*/events' => Http::response($meta, $metaStatus),
        'www.google-analytics.com/*' => Http::response('', 204),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]]),
    ]);
}

test('reports the event to Meta with hashed customer data and moves on', function () {
    pixelNodeFake();

    $conversation = pixelNodeFixture(
        ['event' => 'lead', 'value' => '49,90'],
        fn ($tenant) => [Fx::integration($tenant, IntegrationProvider::MetaPixel)],
    );

    (new FlowExecutor)->startFlow($conversation);

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/123456789012345/events')) {
            return false;
        }

        $event = $request['data'][0];

        return $event['event_name'] === 'Lead'
            && $event['action_source'] === 'chat'
            && $event['user_data']['ph'] === [hash('sha256', '5511999999999')]
            && $event['user_data']['fn'] === [hash('sha256', 'maria')]
            && ! str_contains(json_encode($event), '5511999999999')
            && $event['custom_data']['value'] === 49.9
            && $event['custom_data']['currency'] === 'BRL'
            && $request['access_token'] === Fx::CREDENTIALS['meta_pixel']['access_token'];
    });

    expect(Fx::sentTexts($conversation))->toBe(['Obrigado!'])
        ->and(Integration::sole()->last_used_at)->not->toBeNull();
});

test('one node reports to every selected account under each one\'s own event name', function () {
    pixelNodeFake();

    $conversation = pixelNodeFixture(
        ['event' => 'purchase', 'value' => '120'],
        fn ($tenant) => [
            Fx::integration($tenant, IntegrationProvider::MetaPixel),
            Fx::integration($tenant, IntegrationProvider::GoogleAnalytics),
        ],
    );

    (new FlowExecutor)->startFlow($conversation);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/events')
        && $request['data'][0]['event_name'] === 'Purchase');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'google-analytics.com/mp/collect')) {
            return false;
        }

        return str_contains($request->url(), 'measurement_id=G-TEST12345')
            && $request['events'][0]['name'] === 'purchase'
            && $request['events'][0]['params']['value'] === 120.0
            // Google's normalisation keeps the plus sign; Meta's does not.
            && $request['user_data']['sha256_phone_number'] === [hash('sha256', '+5511999999999')];
    });
});

test('a custom event goes out under the name its author typed', function () {
    pixelNodeFake();

    $conversation = pixelNodeFixture(
        ['event' => 'custom', 'custom_event_name' => 'agendou_visita'],
        fn ($tenant) => [Fx::integration($tenant, IntegrationProvider::MetaPixel)],
    );

    (new FlowExecutor)->startFlow($conversation);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/events')
        && $request['data'][0]['event_name'] === 'agendou_visita');
});

test('a refused event is recorded on the integration and the flow still continues', function () {
    pixelNodeFake(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190]], 400);

    $conversation = pixelNodeFixture(
        [],
        fn ($tenant) => [Fx::integration($tenant, IntegrationProvider::MetaPixel)],
    );

    (new FlowExecutor)->startFlow($conversation);

    $integration = Integration::sole();

    expect(Fx::sentTexts($conversation))->toBe(['Obrigado!'])
        ->and($integration->last_error)->toContain('token')
        ->and($integration->last_error)->not->toContain('Session has expired');
});

test('a disabled integration receives nothing', function () {
    pixelNodeFake();

    $conversation = pixelNodeFixture(
        [],
        fn ($tenant) => [Fx::integration($tenant, IntegrationProvider::MetaPixel, ['enabled' => false])],
    );

    (new FlowExecutor)->startFlow($conversation);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/events'));
    expect(Fx::sentTexts($conversation))->toBe(['Obrigado!']);
});

test('a pixel node cannot point at a payment integration', function () {
    $user = Fx::user();
    $flow = Fx::flow($user->tenant);
    $start = Fx::start($flow);
    $openPix = Fx::integration($user->tenant, IntegrationProvider::OpenPix);

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'px', 'type' => 'pixel', 'data' => ['integration_ids' => [$openPix->id], 'event' => 'lead'], 'position_x' => 280, 'position_y' => 0],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'px', 'condition_value' => null],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.integration_ids.0');
});
