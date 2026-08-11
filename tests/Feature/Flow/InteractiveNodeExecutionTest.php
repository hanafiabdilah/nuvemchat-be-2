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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * A flow shaped: start → interactive (2 buttons) → message per button.
 * Returns [conversation, nodes] so a test can assert where the flow landed.
 */
function interactiveFlowFixture(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Menu']);

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $interactive = $flow->nodes()->create([
        'type' => NodeType::Interactive,
        'data' => [
            'interactive_type' => 'button',
            'body' => 'Hi {{contact.name}}, pick one',
            'footer' => 'Team',
            'buttons' => [
                ['id' => 'btn_yes', 'title' => 'Yes'],
                ['id' => 'btn_no', 'title' => 'No'],
            ],
        ],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $yesNode = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Great!', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    $noNode = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'No worries.', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 100,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $interactive->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $interactive->id, 'target_node_id' => $yesNode->id, 'condition_value' => 'btn_yes']);
    FlowEdge::create(['source_node_id' => $interactive->id, 'target_node_id' => $noNode->id, 'condition_value' => 'btn_no']);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'flow_id' => $flow->id,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
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

    $conversation->messages()->create([
        'external_id' => 'wamid.' . uniqid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'maybe later',
        'sent_at' => now(),
        'meta' => [],
    ]);
    $executor->resumeFlow($conversation->fresh(), 'maybe later');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['interactive']->id);
});
