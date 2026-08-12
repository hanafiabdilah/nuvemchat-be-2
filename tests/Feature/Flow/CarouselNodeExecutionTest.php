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
 * start → carousel → a message node per outgoing branch.
 *
 * With quick replies the carousel has one branch per card button; with link
 * buttons it has none, and the single unlabelled edge is simply "next".
 */
function carouselFlowFixture(string $buttonType = 'quick_reply'): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Ofertas']);

    $isLinkOut = $buttonType === 'cta_url';

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null, 'position_x' => 0, 'position_y' => 0]);
    $carousel = $flow->nodes()->create([
        'type' => NodeType::Interactive,
        'data' => [
            'interactive_type' => 'carousel',
            'body' => 'Olá {{contact.name}}, ofertas pra você!',
            'card_button_type' => $buttonType,
            'cards' => [
                array_merge([
                    'header_type' => 'image',
                    'header_url' => 'https://cdn.example.com/1.jpg',
                    'body' => 'Categoria queridinha',
                ], $isLinkOut
                    ? ['button_label' => 'Aproveitar', 'button_url' => 'https://example.com/1']
                    : ['buttons' => [['id' => 'card_a1', 'title' => 'Ver ofertas']]]),
                array_merge([
                    'header_type' => 'image',
                    'header_url' => 'https://cdn.example.com/2.jpg',
                    'body' => '40% off',
                ], $isLinkOut
                    ? ['button_label' => 'Aproveitar', 'button_url' => 'https://example.com/2']
                    : ['buttons' => [['id' => 'card_b1', 'title' => 'Ver ofertas']]]),
            ],
        ],
        'position_x' => 100,
        'position_y' => 0,
    ]);
    $firstNode = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Card one it is.', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 0,
    ]);
    $secondNode = $flow->nodes()->create([
        'type' => NodeType::Message,
        'data' => ['body' => 'Card two it is.', 'message_type' => 'text', 'wait_for_reply' => false],
        'position_x' => 200,
        'position_y' => 100,
    ]);

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $carousel->id, 'condition_value' => null]);

    if ($isLinkOut) {
        FlowEdge::create(['source_node_id' => $carousel->id, 'target_node_id' => $firstNode->id, 'condition_value' => null]);
    } else {
        FlowEdge::create(['source_node_id' => $carousel->id, 'target_node_id' => $firstNode->id, 'condition_value' => 'card_a1']);
        FlowEdge::create(['source_node_id' => $carousel->id, 'target_node_id' => $secondNode->id, 'condition_value' => 'card_b1']);
    }

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

    return [$conversation, compact('start', 'carousel', 'firstNode', 'secondNode')];
}

/** The inbound message a carousel tap produces — an ordinary button_reply. */
function tapCarouselButton(Conversation $conversation, string $replyId, string $title): void
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

test('a quick-reply carousel goes out with its cards and parks on the node', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    [$conversation, $nodes] = carouselFlowFixture();

    (new FlowExecutor())->startFlow($conversation);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $cards = $body['interactive']['action']['cards'] ?? [];

        return ($body['type'] ?? null) === 'interactive'
            && ($body['interactive']['type'] ?? null) === 'carousel'
            && $body['interactive']['body']['text'] === 'Olá Ana, ofertas pra você!' // {{contact.name}} resolved
            && count($cards) === 2
            && $cards[0]['header']['image']['link'] === 'https://cdn.example.com/1.jpg'
            && $cards[1]['action']['buttons'][0]['quick_reply']['id'] === 'card_b1';
    });

    $sent = Message::where('conversation_id', $conversation->id)->where('sender_type', SenderType::Outgoing)->first();
    expect($sent->message_type)->toBe(MessageType::Interactive);

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['carousel']->id)
        ->and($state->state_data["_interactive_sent_{$nodes['carousel']->id}"])->toBeTrue();
});

test('a tap on the second card routes the flow down that card branch', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    [$conversation, $nodes] = carouselFlowFixture();

    $executor = new FlowExecutor();
    $executor->startFlow($conversation);

    // Both cards carry the same label — only the id says which one was tapped.
    tapCarouselButton($conversation, 'card_b1', 'Ver ofertas');
    $executor->resumeFlow($conversation->fresh(), 'Ver ofertas');

    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['secondNode']->id);

    $bodies = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->pluck('body')
        ->all();
    expect($bodies)->toContain('Card two it is.')
        ->and($bodies)->not->toContain('Card one it is.');
});

test('a link-out carousel sends and moves straight on to the next node', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

    [$conversation, $nodes] = carouselFlowFixture('cta_url');

    (new FlowExecutor())->startFlow($conversation);

    Http::assertSent(function ($request) {
        $cards = $request->data()['interactive']['action']['cards'] ?? [];

        return ($cards[0]['action']['name'] ?? null) === 'cta_url'
            && $cards[0]['action']['parameters']['url'] === 'https://example.com/1';
    });

    // Nothing to wait for, so the flow is already past the carousel.
    $state = FlowState::where('conversation_id', $conversation->id)->first();
    expect($state->current_node_id)->toBe($nodes['firstNode']->id)
        ->and($state->state_data["_interactive_sent_{$nodes['carousel']->id}"] ?? null)->toBeNull();

    $bodies = Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->pluck('body')
        ->all();
    expect($bodies)->toContain('Card one it is.');
});
