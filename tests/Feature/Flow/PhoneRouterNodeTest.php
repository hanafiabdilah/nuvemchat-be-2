<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowState;
use App\Models\Tenant;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\PhoneRouterNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake([
    'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
    'graph.instagram.com/*' => Http::response(['message_id' => 'mid.OUT1']),
]));

/** @return list<array<string, mixed>> */
function phoneRouterRoutes(): array
{
    return [
        ['id' => 'route_sp', 'label' => 'São Paulo', 'ddi' => ['55'], 'ddd' => ['11', '12', '13']],
        ['id' => 'route_br', 'label' => 'Brasil', 'ddi' => ['55'], 'ddd' => []],
        ['id' => 'route_latam', 'label' => 'LatAm', 'ddi' => ['52', '54', '57'], 'ddd' => []],
        ['id' => 'route_do', 'label' => 'República Dominicana', 'ddi' => ['1'], 'ddd' => ['809', '829', '849']],
    ];
}

/** The branch a number takes with every output wired. */
function phoneRouterBranch(?string $phone, ?array $wired = null): ?string
{
    $routes = PhoneRouterNodes::routes(['routes' => phoneRouterRoutes()]);
    $wired ??= PhoneRouterNodes::branches(['routes' => phoneRouterRoutes()]);

    return PhoneRouterNodes::resolve($routes, $phone, $wired)['branch'];
}

/**
 * start → router → one message per wired output, saying which output it is.
 *
 * @param  list<string>|null  $wired
 * @return array{0: Tenant, 1: Flow}
 */
function phoneRouterFixture(?array $wired = null): array
{
    $tenant = Fx::tenant();
    $flow = Fx::flow($tenant, 'Roteador');
    $router = Fx::node($flow, NodeType::PhoneRouter, ['routes' => phoneRouterRoutes()]);

    Fx::edge(Fx::start($flow), $router);

    foreach (PhoneRouterNodes::branches(['routes' => phoneRouterRoutes()]) as $index => $branch) {
        if ($wired !== null && ! in_array($branch, $wired, true)) {
            continue;
        }

        Fx::edge($router, Fx::say($flow, 'Saída '.$branch, 560, $index * 160), $branch);
    }

    return [$tenant, $flow];
}

function phoneRouterRun(Conversation $conversation): array
{
    (new FlowExecutor)->startFlow($conversation);

    return [Fx::sentTexts($conversation)[0] ?? '(nothing)', FlowState::where('conversation_id', $conversation->id)->first()?->state_data ?? []];
}

test('a number is routed by its calling code, the first matching route winning', function () {
    expect(phoneRouterBranch('5511987654321'))->toBe('route_sp')
        ->and(phoneRouterBranch('5521987654321'))->toBe('route_br')
        ->and(phoneRouterBranch('525512345678'))->toBe('route_latam')
        ->and(phoneRouterBranch('5491123456789'))->toBe('route_latam')
        ->and(phoneRouterBranch('18095551234'))->toBe('route_do')
        ->and(phoneRouterBranch('12125551234'))->toBe('other')
        ->and(phoneRouterBranch('351912345678'))->toBe('other')
        ->and(phoneRouterBranch(null))->toBe('unknown')
        ->and(phoneRouterBranch('+55 (11) 98765-4321'))->toBe('route_sp');
});

test('an output without an edge is stepped over rather than swallowing the lead', function () {
    // São Paulo not wired: the number falls through to the whole of Brazil.
    expect(phoneRouterBranch('5511987654321', ['route_br', 'other']))->toBe('route_br')
        // No route for it wired: "other".
        ->and(phoneRouterBranch('525512345678', ['route_br', 'other']))->toBe('other')
        // No number and "unknown" not wired: "other" catches it too.
        ->and(phoneRouterBranch(null, ['other']))->toBe('other')
        // Nothing to take at all.
        ->and(phoneRouterBranch('525512345678', ['route_br']))->toBeNull();
});

test('area codes only count on a route with one calling code', function () {
    $routes = PhoneRouterNodes::routes(['routes' => [
        ['id' => 'mixed', 'ddi' => ['55', '+52', '55'], 'ddd' => ['11']],
        ['id' => '', 'label' => '', 'ddi' => ['1'], 'ddd' => ['8 0 9']],
    ]]);

    expect($routes[0]['ddi'])->toBe(['55', '52'])
        ->and($routes[0]['ddd'])->toBe([])
        ->and($routes[1]['id'])->toBe('route_2')
        ->and($routes[1]['label'])->toBe('+1')
        ->and($routes[1]['ddd'])->toBe(['809']);
});

test('the flow takes the route of the lead\'s number and keeps what it read', function () {
    [$tenant, $flow] = phoneRouterFixture();

    [$sent, $state] = phoneRouterRun(Fx::conversation($tenant, $flow, '5511987654321'));

    expect($sent)->toBe('Saída route_sp')
        ->and($state[PhoneRouterNodes::VARIABLE_DDI])->toBe('55')
        ->and($state[PhoneRouterNodes::VARIABLE_DDD])->toBe('11')
        ->and($state[PhoneRouterNodes::VARIABLE_ROUTE])->toBe('São Paulo');

    [$sent, $state] = phoneRouterRun(Fx::conversation($tenant, $flow, '12125551234'));

    expect($sent)->toBe('Saída other')
        ->and($state[PhoneRouterNodes::VARIABLE_DDI])->toBe('1')
        ->and($state[PhoneRouterNodes::VARIABLE_DDD])->toBe('212')
        ->and($state[PhoneRouterNodes::VARIABLE_ROUTE])->toBe('other');
});

test('a conversation with no phone number takes the unknown output', function () {
    [$tenant, $flow] = phoneRouterFixture();

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Instagram,
        'name' => 'IG',
        'color' => '#e1306c',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => ['access_token' => 'ig-token', 'user_id' => '1789'],
    ]);
    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Instagram,
        'external_id' => '17841400000000001',
        'name' => 'Lia',
        'username' => 'lia',
    ]);
    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '17841400000000001',
        'status' => ConversationStatus::Pending,
    ]);

    [, $state] = phoneRouterRun($conversation);

    // Read off the node the flow went to: whether Instagram accepted the send
    // is not what this is about.
    $landedOn = FlowState::where('conversation_id', $conversation->id)->first()->currentNode;

    expect($landedOn->data['body'])->toBe('Saída unknown')
        ->and($state[PhoneRouterNodes::VARIABLE_DDI])->toBe('')
        ->and($state[PhoneRouterNodes::VARIABLE_ROUTE])->toBe('unknown');
});

test('saving refuses a route named after a fixed output, or a code that is not digits', function () {
    $node = fn (array $route) => [
        ['key' => '1', 'type' => 'start', 'data' => null],
        ['key' => '2', 'type' => 'phone_router', 'data' => ['routes' => [$route]]],
    ];

    expect(fn () => FlowBlueprint::validateNodes($node(['id' => 'other', 'ddi' => ['55']])))->toThrow(ValidationException::class)
        ->and(fn () => FlowBlueprint::validateNodes($node(['id' => 'route_br', 'ddi' => ['+55']])))->toThrow(ValidationException::class)
        ->and(fn () => FlowBlueprint::validateNodes($node(['id' => 'route_br', 'ddi' => ['55'], 'ddd' => ['11']])))->not->toThrow(ValidationException::class)
        // Half-built is fine: autosave runs while the author is still choosing.
        ->and(fn () => FlowBlueprint::validateNodes($node(['id' => 'route_br', 'ddi' => []])))->not->toThrow(ValidationException::class);
});

test('generated flows are told about empty routes and unknown branches', function () {
    $nodes = [
        ['key' => '1', 'type' => 'start', 'data' => null],
        ['key' => '2', 'type' => 'phone_router', 'data' => ['routes' => [
            ['id' => 'route_br', 'label' => 'Brasil', 'ddi' => ['55']],
            ['id' => 'route_empty', 'label' => 'Vazia', 'ddi' => []],
        ]]],
        ['key' => '3', 'type' => 'go_to_flow', 'data' => ['flow_id' => null]],
        ['key' => '4', 'type' => 'go_to_flow', 'data' => ['flow_id' => null]],
    ];
    $edges = [
        ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
        ['source_key' => '2', 'target_key' => '3', 'condition_value' => 'route_br'],
        ['source_key' => '2', 'target_key' => '4', 'condition_value' => 'route_mx'],
    ];

    $problems = implode("\n", FlowBlueprint::structureProblems($nodes, $edges));

    expect($problems)->toContain('has no calling code')
        ->and($problems)->toContain('"route_mx"')
        ->and(FlowBlueprint::specification())->toContain('### phone_router');
});
