<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\RunFlowResponseTimeout;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\LegacyWaitUpgrade;
use App\Services\Flow\WaitResponseNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function legacyNode(string $key, string $type, ?array $data, int $x, int $y = 0): array
{
    return ['key' => $key, 'type' => $type, 'data' => $data, 'position_x' => $x, 'position_y' => $y];
}

function legacyEdge(string $from, string $to, ?string $value = null): array
{
    return ['source_key' => $from, 'target_key' => $to, 'condition_value' => $value];
}

function upgradedNode(array $result, string $key): ?array
{
    return collect($result['nodes'])->firstWhere('key', $key);
}

/** @return list<array{0: string, 1: ?string}> [target, condition_value] of every edge leaving `$key` */
function upgradedEdgesFrom(array $result, string $key): array
{
    return collect($result['edges'])
        ->filter(fn ($edge) => $edge['source_key'] === $key)
        ->map(fn ($edge) => [$edge['target_key'], $edge['condition_value']])
        ->values()
        ->all();
}

$bubble = fn (string $body) => ['messages' => [['message_type' => 'text', 'body' => $body, 'delay' => 0]]];

// ───────────────────────────── The rewrite ─────────────────────────────

it('puts a wait node before whatever followed a message node that waited', function () use ($bubble) {
    // Node 2 never set the switch — absent meant on, the builder's default.
    $result = LegacyWaitUpgrade::upgrade([
        legacyNode('1', 'start', null, 0),
        legacyNode('2', 'message', $bubble('Oi!'), 300),
        legacyNode('3', 'message', ['wait_for_reply' => false] + $bubble('Tudo bem?'), 600),
        legacyNode('4', 'status', ['value' => 'resolved'], 900),
    ], [
        legacyEdge('1', '2'),
        legacyEdge('2', '3'),
        legacyEdge('3', '4'),
    ]);

    $waits = collect($result['nodes'])->where('type', 'wait_response')->values();

    expect($result['changed'])->toBeTrue()
        ->and($waits)->toHaveCount(1);

    $wait = $waits[0];

    expect($wait['data'])->toBe(WaitResponseNodes::defaults())
        ->and(upgradedEdgesFrom($result, '2'))->toBe([[$wait['key'], null]])
        ->and(upgradedEdgesFrom($result, $wait['key']))->toBe([['3', WaitResponseNodes::BRANCH_REPLIED]])
        // Node 3 had the switch off: it only loses the key.
        ->and(upgradedEdgesFrom($result, '3'))->toBe([['4', null]])
        ->and(upgradedNode($result, '3')['data'])->not->toHaveKey('wait_for_reply');

    // The wait takes the spot of the node it now precedes, and the rest of the
    // chain moves a column along instead of being covered.
    expect($wait['position_x'])->toBe(600)
        ->and(upgradedNode($result, '3')['position_x'])->toBe(900)
        ->and(upgradedNode($result, '4')['position_x'])->toBe(1200)
        ->and(upgradedNode($result, '2')['position_x'])->toBe(300);
});

it('only drops the switch from a waiting message node with nothing after it', function () use ($bubble) {
    $result = LegacyWaitUpgrade::upgrade([
        legacyNode('1', 'start', null, 0),
        legacyNode('2', 'message', ['wait_for_reply' => true] + $bubble('Tchau!'), 300),
    ], [legacyEdge('1', '2')]);

    expect(collect($result['nodes'])->where('type', 'wait_response'))->toBeEmpty()
        ->and(upgradedNode($result, '2')['data'])->not->toHaveKey('wait_for_reply');
});

it('turns a response node with a limit into a question and a wait with the same settings', function () {
    $result = LegacyWaitUpgrade::upgrade([
        legacyNode('1', 'start', null, 0),
        legacyNode('ask', 'response', [
            'label' => 'Pergunta: e-mail',
            'body' => 'Qual é o seu e-mail?',
            'message_type' => 'text',
            'variable_key' => 'email',
            'validation' => 'email',
            'error_message' => 'Pode repetir?',
            'timeout_seconds' => 3600,
        ], 300),
        legacyNode('ok', 'message', ['messages' => [['message_type' => 'text', 'body' => 'Valeu!']]], 600),
        legacyNode('again', 'message', ['wait_for_reply' => false, 'messages' => [['message_type' => 'text', 'body' => 'Ainda aí?']]], 600, 200),
    ], [
        legacyEdge('1', 'ask'),
        // Saved before the Response node had two outputs: no value means replied.
        legacyEdge('ask', 'ok'),
        legacyEdge('ask', 'again', 'timeout'),
        // "Ask again" loops back to the question.
        legacyEdge('again', 'ask'),
    ]);

    $questionKey = $result['questions']['ask'];
    $question = upgradedNode($result, $questionKey);
    $wait = upgradedNode($result, 'ask');

    expect($wait['type'])->toBe('wait_response')
        ->and($wait['data'])->toBe([
            'label' => 'Pergunta: e-mail',
            'message' => '',
            'variable_key' => 'email',
            'timeout_seconds' => 3600,
            'timeout_unit' => 'hours',
            'buffer_seconds' => 0,
            'validation' => 'email',
            'error_message' => 'Pode repetir?',
        ]);

    expect($question['type'])->toBe('message')
        ->and($question['data']['messages'])->toBe([['message_type' => 'text', 'body' => 'Qual é o seu e-mail?', 'delay' => 0]])
        ->and($question['position_x'])->toBe(300)
        ->and($wait['position_x'])->toBe(600)
        ->and(upgradedNode($result, 'ok')['position_x'])->toBe(900);

    // Everything that led to the question — the loop included — asks it again.
    expect(upgradedEdgesFrom($result, '1'))->toBe([[$questionKey, null]])
        ->and(upgradedEdgesFrom($result, 'again'))->toBe([[$questionKey, null]])
        ->and(upgradedEdgesFrom($result, $questionKey))->toBe([['ask', null]])
        ->and(upgradedEdgesFrom($result, 'ask'))->toBe([
            ['ok', WaitResponseNodes::BRANCH_REPLIED],
            ['again', WaitResponseNodes::BRANCH_TIMEOUT],
        ]);
});

it('gives a response node without a limit its single output back', function () {
    $result = LegacyWaitUpgrade::upgrade([
        legacyNode('1', 'start', null, 0),
        legacyNode('ask', 'response', ['body' => 'Nome?', 'message_type' => 'text', 'variable_key' => 'nome', 'timeout_seconds' => 0], 300),
        legacyNode('ok', 'message', ['messages' => [['message_type' => 'text', 'body' => 'Valeu!']]], 600),
        legacyNode('stale', 'message', ['messages' => [['message_type' => 'text', 'body' => 'Nunca']]], 600, 200),
    ], [
        legacyEdge('1', 'ask'),
        legacyEdge('ask', 'ok', 'replied'),
        // A limit switched off in the database but not in the builder: this edge
        // never fired, and as a plain edge it could be the one taken.
        legacyEdge('ask', 'stale', 'timeout'),
    ]);

    expect(upgradedNode($result, 'ask')['type'])->toBe('response')
        ->and(upgradedNode($result, 'ask')['data'])->not->toHaveKey('timeout_seconds')
        ->and(upgradedEdgesFrom($result, 'ask'))->toBe([['ok', null]]);
});

it('leaves a flow with nothing to rewrite untouched', function () use ($bubble) {
    $nodes = [legacyNode('1', 'start', null, 0), legacyNode('2', 'message', $bubble('Oi'), 300)];
    $edges = [legacyEdge('1', '2')];

    expect(LegacyWaitUpgrade::upgrade($nodes, $edges)['changed'])->toBeFalse();
});

// ─────────────────────────── The migration ───────────────────────────

it('rewrites stored flows and keeps conversations parked on them in place', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
    Queue::fake();

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Legacy']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $ask = $flow->nodes()->create([
        'type' => NodeType::Response,
        'data' => ['body' => 'Qual é o seu e-mail?', 'message_type' => 'text', 'variable_key' => 'email', 'validation' => 'any', 'timeout_seconds' => 600],
        'position_x' => 300,
        'position_y' => 0,
    ]);
    $thanks = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['messages' => [['message_type' => 'text', 'body' => 'Obrigado!']]],
        'position_x' => 600,
        'position_y' => 0,
    ]);
    $nudge = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['wait_for_reply' => false, 'messages' => [['message_type' => 'text', 'body' => 'Ainda está aí?']]],
        'position_x' => 600,
        'position_y' => 200,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $ask->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $ask->id, 'target_node_id' => $thanks->id, 'condition_value' => 'replied']);
    FlowEdge::create(['source_node_id' => $ask->id, 'target_node_id' => $nudge->id, 'condition_value' => 'timeout']);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => ['phone_number_id' => '111000111', 'access_token' => 'wa-token', 'business_account_id' => '222000222'],
    ]);

    $conversation = function (string $phone) use ($connection) {
        $contact = Contact::create(['connection_id' => $connection->id, 'external_id' => $phone, 'name' => 'Ana', 'username' => $phone]);

        return Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $phone,
            'status' => ConversationStatus::Pending,
        ]);
    };

    // Asked, and its timer is on the queue.
    $asked = $conversation('5511900000001');
    $askedState = FlowState::create([
        'conversation_id' => $asked->id,
        'flow_id' => $flow->id,
        'current_node_id' => $ask->id,
        'state_data' => ["_response_sent_{$ask->id}" => true, "_response_timeout_{$ask->id}" => 'queued-token'],
        'status' => FlowStateStatus::Running,
    ]);

    // Put on the question by a waiting message node, question not sent yet.
    $notAsked = $conversation('5511900000002');
    $notAskedState = FlowState::create([
        'conversation_id' => $notAsked->id,
        'flow_id' => $flow->id,
        'current_node_id' => $ask->id,
        'state_data' => [],
        'status' => FlowStateStatus::Running,
    ]);

    (require database_path('migrations/2026_09_15_000400_move_flow_waits_into_wait_response_nodes.php'))->up();

    $question = FlowNode::where('flow_id', $flow->id)->where('type', NodeType::Message)
        ->whereNotIn('id', [$thanks->id, $nudge->id])->first();

    expect($ask->fresh()->type)->toBe(NodeType::WaitResponse)
        ->and(WaitResponseNodes::timeoutSeconds($ask->fresh()->data))->toBe(600)
        ->and($question)->not->toBeNull()
        ->and($nudge->fresh()->data)->not->toHaveKey('wait_for_reply')
        ->and(FlowEdge::where('source_node_id', $start->id)->value('target_node_id'))->toBe($question->id);

    expect($askedState->fresh()->state_data)
        ->toHaveKey(WaitResponseNodes::parkedKey($ask->id))
        ->toHaveKey(WaitResponseNodes::timeoutKey($ask->id), 'queued-token')
        ->not->toHaveKey("_response_sent_{$ask->id}");

    expect($notAskedState->fresh()->current_node_id)->toBe($question->id);

    $bodies = fn (Conversation $c) => Message::where('conversation_id', $c->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')
        ->pluck('body')
        ->all();

    // The timer queued before the deploy still names the old job, and still
    // takes the branch the author wired.
    (new RunFlowResponseTimeout($askedState->id, $ask->id, 'queued-token'))->handle();

    expect($bodies($asked))->toBe(['Ainda está aí?']);

    // The conversation that had not been asked yet is asked first, and waits.
    (new FlowExecutor)->resumeFlow($notAsked->fresh(), 'oi');

    expect($bodies($notAsked))->toBe(['Qual é o seu e-mail?'])
        ->and($notAskedState->fresh()->current_node_id)->toBe($ask->id)
        ->and($notAskedState->fresh()->state_data)->toHaveKey(WaitResponseNodes::parkedKey($ask->id));
});
