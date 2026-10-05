<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\FlowPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AiAgentFixtures;
use Tests\Support\CatalogFixtures as C;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false, 'ai.proactive.enabled' => false]);
});

/** The customer writes; the armed turn runs at once (sync queue). */
function customerWrites(\App\Models\Conversation $conversation, string $body): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.'.uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), $body);
}

it('leaves a plain AI agent run exactly as it was, even with tools switched on', function () {
    config(['ai.tools.enabled' => true]);

    [$conversation] = AiAgentFixtures::flow();
    C::fake(reset: true);
    AiAgentFixtures::openWithWelcome($conversation);

    customerWrites($conversation, 'Qual o horário de vocês?');

    $run = end(C::$state['runs']);

    // The regression the whole feature is built around: a node that is not
    // ai_tools must not grow a single key — the hub rejects a run over one it
    // does not know.
    expect($run)->not->toHaveKey('tools')
        ->and($run['conversation'])->not->toHaveKey('callbackRef')
        ->and(array_keys($run))->toBe(['agentExternalId', 'responseMode', 'conversation', 'message'])
        ->and($run['message']['content'])->not->toContain('[Shop tools');
});

it('runs an ai_tools node as a plain agent while tools are off', function () {
    config(['ai.tools.enabled' => false]);

    $scene = C::scenario();

    customerWrites($scene['conversation'], 'Tem camiseta preta?');

    $run = end(C::$state['runs']);

    expect($run)->not->toHaveKey('tools')
        ->and($run['conversation'])->not->toHaveKey('callbackRef')
        ->and(Fx::sentTexts($scene['conversation']))->toContain('Posso ajudar!');
});

it('names the ticked tools in the run, after registering the catalog and the credential', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario(['payment' => ['enabled' => false]]);

    customerWrites($scene['conversation'], 'Tem camiseta preta?');

    $run = end(C::$state['runs']);

    // Names only — the definitions live in the agent's catalog on the hub.
    expect($run['tools'])->toBe(['search_products', 'get_product', 'cart_add', 'cart_remove', 'cart_view'])
        // The turn says the catalog wins over prices in the agent's prompt
        // (conversation #28295 answered from the prompt and never looked),
        // with the selling steps only for tools it actually has.
        ->and($run['message']['content'])->toStartWith('[Shop tools')
        ->and($run['message']['content'])->toContain('search_products')->toContain('cart_add')
        ->and($run['message']['content'])->not->toContain('create_payment')
        ->and($run['message']['content'])->toEndWith('Tem camiseta preta?')
        ->and($run['conversation']['callbackRef'])->toStartWith('cr1.')
        ->and($run['metadata']['toolCount'])->toBe(5);

    // The whole catalog (all six) was registered once, before the run.
    $catalog = C::$state['catalogs'][0]['tools'];
    expect(C::$state['catalogs'])->toHaveCount(1)
        ->and(array_column($catalog, 'name'))->toBe(['search_products', 'get_product', 'cart_add', 'cart_remove', 'cart_view', 'create_payment'])
        // The hub's validator wants `required` on every schema and the root closed.
        ->and(collect($catalog)->every(fn ($t) => array_key_exists('required', $t['parameters']) && $t['parameters']['additionalProperties'] === false))->toBeTrue()
        // No tool takes money: the cart total is ours.
        ->and(collect($catalog)->flatMap(fn ($t) => array_keys((array) $t['parameters']['properties']))
            ->filter(fn ($name) => str_contains($name, 'price') || str_contains($name, 'amount') || str_contains($name, 'total'))
            ->all())->toBe([]);

    // And the workspace key the hub calls us with, issued for this agent.
    $agent = $scene['agent']->fresh();
    $key = \App\Models\ApiKey::findOrFail($agent->tools_api_key_id);
    expect(C::$state['deliveries'])->toHaveCount(1)
        ->and(\App\Models\ApiKey::findActive(C::$state['deliveries'][0]['apiKey'])?->id)->toBe($key->id)
        ->and($key->tenant_id)->toBe($scene['tenant']->id);

    // A second turn registers nothing again.
    customerWrites($scene['conversation'], 'E a branca?');
    expect(C::$state['catalogs'])->toHaveCount(1)->and(C::$state['deliveries'])->toHaveCount(1);
});

it('issues and registers a new key when the old one was revoked', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();
    customerWrites($scene['conversation'], 'Tem boné?');

    \App\Models\ApiKey::findOrFail($scene['agent']->fresh()->tools_api_key_id)->update(['revoked_at' => now()]);

    customerWrites($scene['conversation'], 'E mochila?');

    expect(C::$state['deliveries'])->toHaveCount(2)
        ->and(C::$state['catalogs'])->toHaveCount(1);
});

it('never retries a failed run that offered tools, and hands off instead', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();
    $scene['node']->update(['data' => array_merge($scene['node']->data, ['service_hours_behavior' => 'always_ai'])]);
    C::$state['run_response'] = ['id' => 'run_x', 'status' => 'FAILED', 'output' => null, 'error' => ['code' => 'pingly_tool_unavailable', 'message' => 'x']];
    $before = count(C::$state['runs'] ?? []);

    customerWrites($scene['conversation'], 'quero 2 bonés');

    // One run, not two: a retry would let the model call cart_add again.
    expect(count(C::$state['runs']) - $before)->toBe(1)
        ->and(Fx::sentTexts($scene['conversation']))->toContain('Vou te passar para a equipe.');
});

it('stays silent when the hub suppressed the reply because a person took over', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();
    $scene['node']->update(['data' => array_merge($scene['node']->data, ['service_hours_behavior' => 'always_ai'])]);
    C::$state['run_response'] = ['id' => 'run_y', 'status' => 'CANCELLED', 'output' => ['message' => '', 'responseSuppressed' => true]];
    $sentBefore = count(Fx::sentTexts($scene['conversation']));

    customerWrites($scene['conversation'], 'quero 2 bonés');

    // No reply and no handoff message: the empty answer is not an error.
    expect(count(Fx::sentTexts($scene['conversation'])))->toBe($sentBefore)
        ->and(FlowState::where('conversation_id', $scene['conversation']->id)->value('current_node_id'))->toBe($scene['node']->id);
});

it('uses the configured field name for the tools', function () {
    config(['ai.tools.enabled' => true, 'ai.tools.run_field' => 'functions']);

    $scene = C::scenario();

    customerWrites($scene['conversation'], 'oi, tem boné?');

    expect(end(C::$state['runs']))->toHaveKey('functions')->not->toHaveKey('tools');
});

it('leaves through the handoff output, never through a payment one', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();
    C::$state['handoff'] = true;
    $scene['node']->update(['data' => array_merge($scene['node']->data, ['service_hours_behavior' => 'always_ai'])]);

    customerWrites($scene['conversation'], 'quero falar com alguém');

    $texts = Fx::sentTexts($scene['conversation']);

    expect($texts)->toContain('Vou te passar para a equipe.')
        ->and($texts)->not->toContain('Pagamento confirmado, obrigado!');
});

it('takes the paid output once the cart is paid', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();

    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, 5]])->variants->first();
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
    C::call($scene, 'create_payment')->assertOk();

    (new FlowPaymentService)->settle(FlowPayment::firstOrFail(), FlowPaymentStatus::Paid);

    $flowState = FlowState::where('conversation_id', $scene['conversation']->id)->firstOrFail();

    expect(Fx::sentTexts($scene['conversation']))->toContain('Pagamento confirmado, obrigado!')
        ->and($flowState->state_data['order_total'])->toBe('R$ 39,90')
        ->and($flowState->state_data['order_items'])->toBe('1x Boné')
        ->and($flowState->state_data['payment_status'])->toBe('paid');
});

it('takes the payment_failed output when the Pix expires', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();

    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, null]])->variants->first();
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
    C::call($scene, 'create_payment')->assertOk();

    (new FlowPaymentService)->settle(FlowPayment::firstOrFail(), FlowPaymentStatus::Expired);

    expect(Fx::sentTexts($scene['conversation']))->toContain('O Pix expirou.')
        ->and(\App\Models\Order::firstOrFail()->status)->toBe(\App\Enums\Catalog\OrderStatus::Expired);
});

it('stays with the AI when nothing is drawn after the payment', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();
    $scene['node']->outgoingEdges()->where('condition_value', 'paid')->delete();

    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, null]])->variants->first();
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
    C::call($scene, 'create_payment')->assertOk();

    (new FlowPaymentService)->settle(FlowPayment::firstOrFail(), FlowPaymentStatus::Paid);

    $flowState = FlowState::where('conversation_id', $scene['conversation']->id)->firstOrFail();

    expect($flowState->current_node_id)->toBe($scene['node']->id)
        ->and($flowState->status)->toBe(FlowStateStatus::Running)
        ->and($scene['conversation']->fresh()->status)->toBe(ConversationStatus::AiHandling);
});

it('records the payment but does not replay the flow over a person who took the thread', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();

    $variant = C::product($scene['tenant'], 'Boné', ['' => [3990, 4]])->variants->first();
    C::call($scene, 'cart_add', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
    C::call($scene, 'create_payment')->assertOk();

    $scene['conversation']->update(['status' => ConversationStatus::Active]);

    (new FlowPaymentService)->settle(FlowPayment::firstOrFail(), FlowPaymentStatus::Paid);

    expect(Fx::sentTexts($scene['conversation']))->not->toContain('Pagamento confirmado, obrigado!')
        ->and($variant->fresh()->stock)->toBe(3);
});

it('does not greet a second time when the flow comes back to the node', function () {
    config(['ai.tools.enabled' => true]);

    $scene = C::scenario();

    $flowState = FlowState::where('conversation_id', $scene['conversation']->id)->firstOrFail();
    $welcomes = fn () => collect(Fx::sentTexts($scene['conversation']))->filter(fn ($t) => $t === 'Oi! Como posso ajudar?')->count();
    $before = $welcomes();

    // As if a branch had looped back here.
    (new class extends FlowExecutor
    {
        public function reenter(FlowState $state, $node): void
        {
            $this->executeFromNode($state, $node);
        }
    })->reenter($flowState, $scene['node']);

    expect($welcomes())->toBe($before);
});

it('is accepted by the flow file contract with its dynamic outputs', function () {
    $nodes = [
        ['key' => '1', 'type' => 'start', 'data' => null],
        ['key' => '2', 'type' => 'ai_tools', 'data' => ['ai_hub_agent_id' => 1, 'welcoming_message' => 'Oi', 'capabilities' => ['catalog' => true, 'cart' => true, 'payment' => ['enabled' => true, 'integration_id' => 4]]]],
        ['key' => '3', 'type' => 'message', 'data' => ['body' => 'ok']],
        ['key' => '4', 'type' => 'message', 'data' => ['body' => 'no']],
    ];

    $problems = \App\Services\Flow\FlowBlueprint::structureProblems($nodes, [
        ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
        ['source_key' => '2', 'target_key' => '3', 'condition_value' => 'paid'],
        ['source_key' => '2', 'target_key' => '4', 'condition_value' => 'handoff'],
    ]);

    expect($problems)->toBe([]);

    // Without payment, "paid" is not an output the node has.
    $nodes[1]['data']['capabilities']['payment']['enabled'] = false;

    $problems = \App\Services\Flow\FlowBlueprint::structureProblems($nodes, [
        ['source_key' => '1', 'target_key' => '2', 'condition_value' => null],
        ['source_key' => '2', 'target_key' => '3', 'condition_value' => 'paid'],
        ['source_key' => '2', 'target_key' => '4', 'condition_value' => 'handoff'],
    ]);

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain('payment_failed');
});

it('names every branching node type in its error, the new one included', function () {
    expect(\App\Services\Flow\FlowBlueprint::branchingTypesSentence())
        ->toBe('condition, http_request, wait_response, payment, invoice, receipt, ai_media, interactive and ai_tools');
});

it('knows both AI node types are AI nodes', function () {
    expect(NodeType::AIAgent->isAiAgent())->toBeTrue()
        ->and(NodeType::AiTools->isAiAgent())->toBeTrue()
        ->and(NodeType::Payment->isAiAgent())->toBeFalse();
});
