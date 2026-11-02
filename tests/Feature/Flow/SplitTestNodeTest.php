<?php

use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSplitAssignment;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Flow\FlowBlueprint;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\SplitTestNodes;
use App\Services\Flow\SplitTests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]));

/**
 * start → split → one message per wired variant, saying the variant's label.
 *
 * @param  list<array<string, mixed>>  $variants
 * @param  list<string>|null  $wired  variant ids that get an edge (all by default)
 * @return array{0: Tenant, 1: Flow, 2: FlowNode}
 */
function splitFixture(array $variants, string $mode = SplitTestNodes::MODE_ROTATE, ?array $wired = null): array
{
    $tenant = Fx::tenant();
    $flow = Fx::flow($tenant, 'Funil');
    $split = Fx::node($flow, NodeType::SplitTest, ['mode' => $mode, 'variants' => $variants]);

    Fx::edge(Fx::start($flow), $split);

    foreach ($variants as $index => $variant) {
        if ($wired !== null && ! in_array($variant['id'], $wired, true)) {
            continue;
        }

        Fx::edge($split, Fx::say($flow, 'Funil '.$variant['label'], 560, $index * 180), $variant['id']);
    }

    return [$tenant, $flow, $split];
}

/**
 * Run $count fresh conversations through the flow and return, in order, the
 * funnel each one landed in.
 *
 * @return list<string>
 */
function splitRun(Tenant $tenant, Flow $flow, int $count): array
{
    $landed = [];

    for ($i = 0; $i < $count; $i++) {
        $conversation = Fx::conversation($tenant, $flow, '55119999900'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        (new FlowExecutor)->startFlow($conversation);
        $landed[] = Fx::sentTexts($conversation)[0] ?? '(nothing)';
    }

    return $landed;
}

function splitVariant(string $id, string $label, int $weight = 1, ?int $limit = null): array
{
    return ['id' => $id, 'label' => $label, 'weight' => $weight, 'limit' => $limit];
}

test('rotate deals conversations out alternately and remembers the variant', function () {
    [$tenant, $flow] = splitFixture([splitVariant('va', 'A'), splitVariant('vb', 'B')]);

    expect(splitRun($tenant, $flow, 4))->toBe(['Funil A', 'Funil B', 'Funil A', 'Funil B']);

    $state = FlowState::latest('id')->first();
    expect($state->state_data[SplitTestNodes::VARIABLE])->toBe('B')
        ->and(FlowSplitAssignment::where('variant_id', 'va')->count())->toBe(2)
        ->and(FlowSplitAssignment::where('variant_id', 'vb')->count())->toBe(2);
});

test('rotate honours the weights', function () {
    [$tenant, $flow] = splitFixture([splitVariant('va', 'A', weight: 2), splitVariant('vb', 'B', weight: 1)]);

    $landed = array_count_values(splitRun($tenant, $flow, 6));

    expect($landed)->toBe(['Funil A' => 4, 'Funil B' => 2]);
});

test('sequence gives each variant its limit and the last one the rest', function () {
    [$tenant, $flow] = splitFixture([
        splitVariant('va', 'A', limit: 2),
        splitVariant('vb', 'B', limit: 1),
        splitVariant('vc', 'C', limit: 1),
    ], SplitTestNodes::MODE_SEQUENCE);

    expect(splitRun($tenant, $flow, 6))->toBe(['Funil A', 'Funil A', 'Funil B', 'Funil C', 'Funil C', 'Funil C']);
});

test('a variant with no edge receives no conversations', function () {
    [$tenant, $flow] = splitFixture(
        [splitVariant('va', 'A'), splitVariant('vb', 'B'), splitVariant('vc', 'C')],
        wired: ['va', 'vc'],
    );

    expect(splitRun($tenant, $flow, 4))->toBe(['Funil A', 'Funil C', 'Funil A', 'Funil C'])
        ->and(FlowSplitAssignment::where('variant_id', 'vb')->exists())->toBeFalse();
});

test('with nothing wired the path ends without counting anyone', function () {
    [$tenant, $flow] = splitFixture([splitVariant('va', 'A'), splitVariant('vb', 'B')], wired: []);

    expect(splitRun($tenant, $flow, 1))->toBe(['(nothing)'])
        ->and(FlowSplitAssignment::count())->toBe(0);
});

test('a conversation that passes the split again keeps its variant and is counted once', function () {
    [$tenant, $flow, $split] = splitFixture([splitVariant('va', 'A'), splitVariant('vb', 'B')]);
    $conversation = Fx::conversation($tenant, $flow);

    (new FlowExecutor)->startFlow($conversation);
    $state = FlowState::where('conversation_id', $conversation->id)->sole();
    $candidates = SplitTestNodes::variants($split->data);

    expect(SplitTests::assign($state, $split, $candidates))->toBe('va')
        ->and(SplitTests::assign($state, $split, $candidates))->toBe('va')
        ->and(FlowSplitAssignment::count())->toBe(1);
});

test('restarting the test counts from zero again', function () {
    Carbon::setTestNow('2026-11-01 10:00:00');
    [$tenant, $flow, $split] = splitFixture([splitVariant('va', 'A', limit: 1), splitVariant('vb', 'B')], SplitTestNodes::MODE_SEQUENCE);

    expect(splitRun($tenant, $flow, 2))->toBe(['Funil A', 'Funil B']);

    Carbon::setTestNow('2026-11-01 11:00:00');
    $split->update(['data' => array_merge($split->data, ['started_at' => now()->toIso8601String()])]);

    // A has its one conversation again before B takes over.
    expect(splitRun($tenant, $flow, 2))->toBe(['Funil A', 'Funil B'])
        ->and(FlowSplitAssignment::count())->toBe(4);

    Carbon::setTestNow();
});

test('results count conversations, replies and sales per variant', function () {
    [$tenant, $flow] = splitFixture([splitVariant('va', 'A'), splitVariant('vb', 'B')]);
    splitRun($tenant, $flow, 4);

    $conversations = FlowSplitAssignment::orderBy('id')->get()->map(fn ($row) => Conversation::find($row->conversation_id));
    [$a1, $b1, $a2] = [$conversations[0], $conversations[1], $conversations[2]];

    // A1 and B1 write back; A1 also buys, twice.
    foreach ([$a1, $b1] as $conversation) {
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => 'quero',
            'sent_at' => now(),
        ]);
    }

    foreach ([4990, 1990] as $index => $cents) {
        Sale::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $a1->id,
            'source' => Sale::SOURCE_RECEIPT,
            'source_id' => $index + 1,
            'amount_cents' => $cents,
            'currency' => $tenant->currency(),
            'sold_at' => now()->addMinute(),
        ]);
    }

    // A sale in another currency is not added to these.
    Sale::create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $a2->id,
        'source' => Sale::SOURCE_RECEIPT,
        'source_id' => 99,
        'amount_cents' => 100000,
        'currency' => 'XXX',
        'sold_at' => now()->addMinute(),
    ]);

    $user = Fx::user($tenant, ['flows.view', 'statistics.tenant.view']);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/flows/{$flow->id}/split-tests")->assertOk();

    $variants = collect($response->json('data.tests.0.variants'))->keyBy('id');

    expect($response->json('data.sales_visible'))->toBeTrue()
        ->and($variants['va'])->toMatchArray(['label' => 'A', 'conversations' => 2, 'replied' => 1, 'buyers' => 1, 'sales' => 2, 'revenue_cents' => 6980])
        ->and($variants['vb'])->toMatchArray(['label' => 'B', 'conversations' => 2, 'replied' => 1, 'buyers' => 0, 'sales' => 0, 'revenue_cents' => 0]);
});

test('sales stay hidden from a role that cannot read statistics', function () {
    [$tenant, $flow] = splitFixture([splitVariant('va', 'A'), splitVariant('vb', 'B')]);
    splitRun($tenant, $flow, 2);

    $user = Fx::user($tenant, ['flows.view']);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/flows/{$flow->id}/split-tests")->assertOk();

    expect($response->json('data.sales_visible'))->toBeFalse()
        ->and($response->json('data.tests.0.variants.0.conversations'))->toBe(1)
        ->and($response->json('data.tests.0.variants.0.revenue_cents'))->toBeNull();

    // Another workspace's flow is not there to read.
    $other = Fx::user(Fx::tenant(), ['flows.view']);
    $this->actingAs($other, 'sanctum')->getJson("/api/flows/{$flow->id}/split-tests")->assertNotFound();
});

test('saving accepts a split test and refuses a malformed variant id', function () {
    $user = Fx::user(null, ['flows.update']);
    $flow = Fx::flow($user->tenant);
    $start = Fx::start($flow);

    $payload = fn (string $variantId) => [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'split', 'type' => 'split_test', 'data' => [
                'mode' => 'rotate',
                'variants' => [['id' => $variantId, 'label' => 'A'], ['id' => 'variant_b', 'label' => 'B']],
            ], 'position_x' => 280, 'position_y' => 0],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'split', 'condition_value' => null],
        ],
    ];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload('has space'))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.variants.0.id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload('variant_a'))
        ->assertOk();
});

test('generated flows are told about unknown branches and shared variant ids', function () {
    $nodes = [
        ['key' => '1', 'type' => 'start', 'data' => null],
        ['key' => '2', 'type' => 'split_test', 'data' => ['variants' => [['id' => 'x'], ['id' => 'y']]]],
        ['key' => '3', 'type' => 'split_test', 'data' => ['variants' => [['id' => 'x'], ['id' => 'z']]]],
        ['key' => '4', 'type' => 'go_to_flow', 'data' => ['flow_id' => null]],
    ];
    $edges = [
        ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
        ['source_key' => '2', 'target_key' => '3', 'condition_value' => 'x'],
        ['source_key' => '3', 'target_key' => '4', 'condition_value' => 'nope'],
    ];

    $problems = implode("\n", FlowBlueprint::structureProblems($nodes, $edges));

    expect($problems)->toContain('must be one of that node\'s variant ids')
        ->and($problems)->toContain('Variant id "x" is used twice')
        ->and(FlowBlueprint::specification())->toContain('### split_test');
});
