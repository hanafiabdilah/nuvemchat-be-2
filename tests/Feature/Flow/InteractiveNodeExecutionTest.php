<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowState;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\InteractiveNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * A flow shaped: start → interactive (2 buttons) → message per button.
 * Returns [conversation, nodes] so a test can assert where the flow landed.
 *
 * The channel is a parameter because the node runs on all of them: WhatsApp
 * Official draws buttons, everything else gets the same options as a numbered
 * menu, and both must route a pick to the same branch.
 */
function interactiveFlowFixture(Channel $channel = Channel::WhatsappOfficial, array $nodeData = []): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Menu']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $interactive = $flow->nodes()->create([
        'type' => NodeType::Interactive,
        'data' => array_replace([
            'interactive_type' => 'button',
            'body' => 'Hi {{contact.name}}, pick one',
            'footer' => 'Team',
            'buttons' => [
                ['id' => 'btn_yes', 'title' => 'Yes'],
                ['id' => 'btn_no', 'title' => 'No'],
            ],
        ], $nodeData),
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $yesNode = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Great!', 'message_type' => 'text'],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    $noNode = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'No worries.', 'message_type' => 'text'],
        'position_x' => 200,
        'position_y' => 100,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $interactive->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $interactive->id, 'target_node_id' => $yesNode->id, 'condition_value' => 'btn_yes']);
    FlowEdge::create(['source_node_id' => $interactive->id, 'target_node_id' => $noNode->id, 'condition_value' => 'btn_no']);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => $channel->value,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => $channel === Channel::WhatsappOfficial
            ? [
                'phone_number_id' => '111000111',
                'access_token' => 'wa-token',
                'business_account_id' => '222000222',
            ]
            : ['token' => 'apiway-token', 'instance_id' => 'inst-1'],
    ]);

    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Pending,
    ]);

    return [$conversation, compact('start', 'interactive', 'yesNode', 'noNode')];
}

/** The inbound message a tap produces: the raw Cloud API entry, reply id and all. */
function tapButton(Conversation $conversation, string $replyId, string $title): void
{
    $conversation->messages()->create([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Interactive,
        'body' => $title,
        'sent_at' => now(),
        'meta' => [
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'type' => 'interactive',
                        'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $replyId, 'title' => $title]],
                    ]],
                ],
            ]],
        ],
    ]);
}

test('an interactive node sends the buttons and waits', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    [$conversation, $nodes] = interactiveFlowFixture();

    (new FlowExecutor())->startFlow($conversation);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['type'] ?? null) === 'interactive'
            && $body['interactive']['body']['text'] === 'Hi Ana, pick one' // {{contact.name}} resolved
            && $body['interactive']['action']['buttons'][0]['reply']['id'] === 'btn_yes'
            && $body['interactive']['action']['buttons'][1]['reply']['id'] === 'btn_no';
    });

    $sent = Message::where('conversation_id', $conversation->id)->where('sender_type', SenderType::Outgoing)->first();
    expect($sent->message_type)->toBe(MessageType::Interactive);

    // Parked on the interactive node with its "asked" flag set, like a Response node.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['interactive']->id)
        ->and($state->state_data["_interactive_sent_{$nodes['interactive']->id}"])->toBeTrue();
});

test('a tapped button routes the flow down that option branch', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    [$conversation, $nodes] = interactiveFlowFixture();

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    tapButton($conversation, 'btn_no', 'No');
    $executor->resumeFlow($conversation->fresh(), 'No');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['noNode']->id);

    $bodies = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->pluck('body')
        ->all();
    expect($bodies)->toContain('No worries.')
        ->and($bodies)->not->toContain('Great!');
});

test('a typed answer matching no option leaves the flow on the node', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    [$conversation, $nodes] = interactiveFlowFixture();

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    typeAnswer($conversation, 'maybe later');
    $executor->resumeFlow($conversation->fresh(), 'maybe later');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['interactive']->id);
});

/** A plain typed reply, the way every channel without buttons answers a menu. */
function typeAnswer(Conversation $conversation, string $body): void
{
    $conversation->messages()->create([
        'external_id' => 'msg.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
        'meta' => [],
    ]);
}

/**
 * API Way stands in for "a channel with no buttons" throughout: it is real
 * WhatsApp, which is where this actually matters, and it speaks over the HTTP
 * client so a fake reaches it (the Telegram SDK uses Guzzle directly).
 */
function apiwayOk(): void
{
    Http::fake([
        'whats-api.ipbr.pro/*' => Http::response(['success' => true, 'data' => ['id' => 'apiway.' . uniqid()]]),
    ]);
}

test('on a channel without buttons the options go out as a numbered menu', function () {
    apiwayOk();

    [$conversation, $nodes] = interactiveFlowFixture(Channel::WhatsappApiway);

    (new FlowExecutor())->startFlow($conversation);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->firstOrFail();

    expect($sent->message_type)->toBe(MessageType::Text)
        ->and($sent->body)->toBe("Hi Ana, pick one\n\n1. Yes\n2. No\n\nTeam");

    // And it waits exactly as the button version does.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['interactive']->id)
        ->and($state->state_data["_interactive_sent_{$nodes['interactive']->id}"])->toBeTrue();
});

test('a typed number takes the same branch a tap would have taken', function () {
    apiwayOk();

    [$conversation, $nodes] = interactiveFlowFixture(Channel::WhatsappApiway);

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    typeAnswer($conversation, '2');
    $executor->resumeFlow($conversation->fresh(), '2');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['noNode']->id);
});

test('a miss answers with the invalid message and stays put while no branch is wired', function () {
    apiwayOk();

    [$conversation, $nodes] = interactiveFlowFixture(Channel::WhatsappApiway, [
        'invalid_message' => 'Não entendi, {{contact.name}}. Responda com o número.',
    ]);

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    typeAnswer($conversation, 'oi');
    $executor->resumeFlow($conversation->fresh(), 'oi');

    $bodies = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)->pluck('body')->all();

    expect($bodies)->toContain('Não entendi, Ana. Responda com o número.');

    // No invalid edge exists, so the node keeps waiting — the behaviour every
    // flow saved before this branch existed relies on.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['interactive']->id)
        ->and($state->state_data[InteractiveNodes::attemptsKey($nodes['interactive']->id)])->toBe(1);
});

test('the invalid branch is taken once the attempt limit is reached', function () {
    apiwayOk();

    [$conversation, $nodes] = interactiveFlowFixture(Channel::WhatsappApiway, [
        'invalid_message' => 'Não entendi.',
        'invalid_attempts' => 2,
    ]);

    $flow = $nodes['interactive']->flow;
    $fallback = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Vou te transferir.', 'message_type' => 'text'],
        'position_x' => 300,
        'position_y' => 200,
    ]);
    FlowEdge::create([
        'source_node_id' => $nodes['interactive']->id,
        'target_node_id' => $fallback->id,
        'condition_value' => InteractiveNodes::BRANCH_INVALID,
    ]);

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    typeAnswer($conversation, 'oi');
    $executor->resumeFlow($conversation->fresh(), 'oi');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['interactive']->id);

    typeAnswer($conversation, 'alguem?');
    $executor->resumeFlow($conversation->fresh(), 'alguem?');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($fallback->id)
        ->and($state->state_data)->not->toHaveKey(InteractiveNodes::attemptsKey($nodes['interactive']->id));

    $bodies = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)->pluck('body')->all();

    // The explanation lands before the branch runs, not after it.
    expect(array_search('Vou te transferir.', $bodies, true))
        ->toBeGreaterThan(array_search('Não entendi.', $bodies, true));
});

test('a picked option clears the miss tally', function () {
    apiwayOk();

    [$conversation, $nodes] = interactiveFlowFixture(Channel::WhatsappApiway, [
        'invalid_message' => 'Não entendi.',
        'invalid_attempts' => 5,
    ]);

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    typeAnswer($conversation, 'oi');
    $executor->resumeFlow($conversation->fresh(), 'oi');

    typeAnswer($conversation, '1');
    $executor->resumeFlow($conversation->fresh(), '1');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['yesNode']->id)
        ->and($state->state_data)->not->toHaveKey(InteractiveNodes::attemptsKey($nodes['interactive']->id));
});

test('a list keeps its sections and numbers straight through them', function () {
    apiwayOk();

    [$conversation] = interactiveFlowFixture(Channel::WhatsappApiway, [
        'interactive_type' => 'list',
        'body' => 'Como podemos ajudar?',
        'footer' => 'Responda com o número.',
        'button_label' => 'Abrir menu',
        'buttons' => [],
        'sections' => [
            ['title' => 'Suporte', 'rows' => [
                ['id' => 'row_tec', 'title' => 'Problema técnico', 'description' => 'Conexão, lentidão'],
                ['id' => 'row_fat', 'title' => 'Faturamento'],
            ]],
            ['title' => 'Comercial', 'rows' => [
                ['id' => 'row_plano', 'title' => 'Comprar um plano'],
            ]],
        ],
    ]);

    (new FlowExecutor())->startFlow($conversation);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)->firstOrFail();

    expect($sent->body)->toBe(implode("\n", [
        'Como podemos ajudar?',
        '',
        'Suporte',
        '1. Problema técnico',
        '   Conexão, lentidão',
        '2. Faturamento',
        '',
        'Comercial',
        '3. Comprar um plano',
        '',
        'Responda com o número.',
    ]));

    // The third row's number must still reach the branch its edge carries.
    expect(InteractiveNodes::matchOption($sent->conversation->connection->flow->nodes
        ->firstWhere('type', NodeType::Interactive)->data, null, '3'))->toBe('row_plano');
});

test('a carousel goes out as one media message per card, numbers beside the picture', function () {
    apiwayOk();

    [$conversation] = interactiveFlowFixture(Channel::WhatsappApiway, [
        'interactive_type' => 'carousel',
        'body' => 'Ofertas pra você',
        'footer' => '',
        'buttons' => [],
        'card_button_type' => 'quick_reply',
        'cards' => [
            [
                'header_type' => 'image', 'header_url' => 'https://cdn.test/basic.jpg', 'body' => 'Plano Basic',
                'buttons' => [['id' => 'card_basic', 'title' => 'Quero este']],
            ],
            [
                'header_type' => 'image', 'header_url' => 'https://cdn.test/pro.jpg', 'body' => 'Plano Pro',
                'buttons' => [['id' => 'card_pro', 'title' => 'Quero este']],
            ],
        ],
    ]);

    (new FlowExecutor())->startFlow($conversation);

    $sent = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)->orderBy('id')->get();

    expect($sent)->toHaveCount(3)
        ->and($sent[0]->message_type)->toBe(MessageType::Text)
        ->and($sent[0]->body)->toBe('Ofertas pra você')
        ->and($sent[1]->message_type)->toBe(MessageType::Image)
        ->and($sent[1]->body)->toBe("Plano Basic\n1. Quero este")
        ->and($sent[2]->message_type)->toBe(MessageType::Image)
        // Numbering runs across cards, so the second card is 2 and not 1 again.
        ->and($sent[2]->body)->toBe("Plano Pro\n2. Quero este");
});
