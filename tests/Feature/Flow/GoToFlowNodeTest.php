<?php

use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowState;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\FlowLinkNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]));

/**
 * "Vendas": start → ask the name (nome) → go to "Suporte".
 * "Suporte": start → "Oi {{nome}}, aqui é o suporte."
 *
 * @return array{0: \App\Models\Conversation, 1: Flow, 2: Flow}
 */
function goToFlowFixture(bool $carryVariables = true): array
{
    $tenant = Fx::tenant();

    $support = Fx::flow($tenant, 'Suporte');
    $greeting = Fx::say($support, 'Oi {{nome}}, aqui é o suporte.', 280);
    Fx::edge(Fx::start($support), $greeting);

    $sales = Fx::flow($tenant, 'Vendas');
    $ask = Fx::node($sales, NodeType::Response, [
        'body' => 'Qual é o seu nome?',
        'message_type' => 'text',
        'variable_key' => 'nome',
        'validation' => 'any',
    ]);
    $jump = Fx::node($sales, NodeType::GoToFlow, ['flow_id' => $support->id, 'carry_variables' => $carryVariables], 560);

    Fx::edge(Fx::start($sales), $ask);
    Fx::edge($ask, $jump, 'replied');

    return [Fx::conversation($tenant, $sales), $sales, $support];
}

test('continues in the other flow from its start node, with the answers collected so far', function () {
    [$conversation, , $support] = goToFlowFixture();

    (new FlowExecutor)->startFlow($conversation);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Ana');

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect(Fx::sentTexts($conversation))->toBe(['Qual é o seu nome?', 'Oi Ana, aqui é o suporte.'])
        ->and($state->flow_id)->toBe($support->id)
        ->and($state->status)->toBe(FlowStateStatus::Running);

    $note = Fx::notes($conversation, FlowLinkNodes::INFO_JUMPED)->sole();
    expect($note->meta['info']['params']['flow'])->toBe('Suporte');
});

test('without carry_variables the other flow starts clean', function () {
    [$conversation] = goToFlowFixture(carryVariables: false);

    (new FlowExecutor)->startFlow($conversation);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Ana');

    expect(Fx::sentTexts($conversation))->toContain('Oi , aqui é o suporte.');
});

test('a target flow that no longer exists ends the flow with a note', function () {
    [$conversation, , $support] = goToFlowFixture();
    $support->delete();

    (new FlowExecutor)->startFlow($conversation);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'Ana');

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($state->status)->toBe(FlowStateStatus::Failed)
        ->and(Fx::notes($conversation, FlowLinkNodes::INFO_TARGET_MISSING))->toHaveCount(1);
});

test('two flows pointing at each other stop instead of looping forever', function () {
    $tenant = Fx::tenant();
    $ping = Fx::flow($tenant, 'Ping');
    $pong = Fx::flow($tenant, 'Pong');

    Fx::edge(Fx::start($ping), Fx::node($ping, NodeType::GoToFlow, ['flow_id' => $pong->id]));
    Fx::edge(Fx::start($pong), Fx::node($pong, NodeType::GoToFlow, ['flow_id' => $ping->id]));

    $conversation = Fx::conversation($tenant, $ping);

    (new FlowExecutor)->startFlow($conversation);

    expect(FlowState::where('conversation_id', $conversation->id)->sole()->status)->toBe(FlowStateStatus::Failed)
        ->and(Fx::notes($conversation, FlowLinkNodes::INFO_JUMPED))->toHaveCount(FlowLinkNodes::MAX_CONSECUTIVE_JUMPS)
        ->and(Fx::notes($conversation, FlowLinkNodes::INFO_LOOP))->toHaveCount(1);
});

test('a flow cannot be saved continuing into itself, nor into another workspace\'s flow', function () {
    $user = Fx::user();
    $flow = Fx::flow($user->tenant);
    $start = Fx::start($flow);
    $foreign = Fx::flow(Fx::tenant(), 'Alheio');
    $sibling = Fx::flow($user->tenant, 'Suporte');

    $payload = fn (int $target) => [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'jump', 'type' => 'go_to_flow', 'data' => ['flow_id' => $target], 'position_x' => 280, 'position_y' => 0],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'jump', 'condition_value' => null],
        ],
    ];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($flow->id))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.flow_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($foreign->id))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.flow_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($sibling->id))
        ->assertOk();
});
