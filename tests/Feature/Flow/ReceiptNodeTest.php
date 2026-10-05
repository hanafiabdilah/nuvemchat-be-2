<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\SendPixelEvent;
use App\Models\Conversation;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowReceipt;
use App\Models\FlowState;
use App\Models\Message;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\ReceiptNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiAgentFixtures;
use Tests\Support\IntegrationFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Storage::fake('local');
});

/**
 * The shared AI flow with its agent node turned into a receipt node:
 * start → receipt → (approved: "Obrigado!" | rejected: "Vou chamar alguém.").
 *
 * @return array{0: Conversation, 1: FlowNode}
 */
function receiptFlow(array $data = []): array
{
    [$conversation, $node] = AiAgentFixtures::flow();

    $node->update([
        'type' => NodeType::Receipt,
        'data' => array_merge(ReceiptNodes::defaults(), [
            'ai_hub_agent_id' => $node->data['ai_hub_agent_id'],
            'message' => 'Envie o comprovante.',
            'invalid_message' => 'Não consegui confirmar. Envie de novo.',
        ], $data),
    ]);

    foreach (['approved' => 'Obrigado!', 'rejected' => 'Vou chamar alguém.'] as $branch => $body) {
        $target = FlowNode::create([
            'flow_id' => $node->flow_id,
            'type' => NodeType::Message,
            'data' => ['body' => $body, 'message_type' => 'text'],
            'position_x' => 300,
            'position_y' => 0,
        ]);
        FlowEdge::create(['source_node_id' => $node->id, 'target_node_id' => $target->id, 'condition_value' => $branch]);
    }

    return [$conversation, $node->fresh()];
}

/** The channel accepts every send; each hub run answers `$reply` under its own id. */
function fakeReceiptHub(string $reply): void
{
    Http::fake([
        'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]),
        'api-ia.ipbr.pro/*' => fn () => Http::response([
            'id' => 'run_'.uniqid(),
            'status' => 'COMPLETED',
            'output' => ['message' => $reply, 'handoff' => false],
        ]),
    ]);
}

/** What the model "read" off the picture. */
function receiptReading(array $overrides = []): string
{
    return json_encode(array_merge([
        'is_receipt' => true, 'completed' => true, 'amount' => '49.90', 'currency' => 'BRL',
        'payer' => 'Ana Souza', 'recipient' => 'Loja Aurora LTDA', 'recipient_key' => null,
        'date' => '2026-10-05', 'transaction_id' => 'E123ABC', 'recipient_matches' => null, 'note' => '',
    ], $overrides));
}

function customerSendsReceipt(Conversation $conversation, string $bytes = 'receipt-bytes', string $name = 'comprovante.jpg', MessageType $type = MessageType::Image): Message
{
    $path = 'media/'.uniqid().'/'.$name;
    Storage::disk('local')->put($path, $bytes);

    $message = AiAgentFixtures::incomingMedia($conversation, $type, null, $path);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');

    return $message;
}

function receiptOutgoing(Conversation $conversation): array
{
    return $conversation->messages()
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', '!=', MessageType::Info)
        ->orderBy('id')->pluck('body')->all();
}

test('an accepted receipt takes the approved branch and exposes what was read', function () {
    [$conversation] = receiptFlow(['expected_amount' => '49,90']);
    fakeReceiptHub(receiptReading());

    AiAgentFixtures::openWithWelcome($conversation);
    expect(receiptOutgoing($conversation))->toBe(['Envie o comprovante.']);

    customerSendsReceipt($conversation);

    $state = FlowState::where('conversation_id', $conversation->id)->first()->state_data;
    $run = AiAgentFixtures::hubRuns()[0];

    expect(receiptOutgoing($conversation))->toBe(['Envie o comprovante.', 'Obrigado!'])
        ->and($state['receipt_status'])->toBe('approved')
        ->and($state['receipt_amount'])->toBe('49,90')
        ->and($state['receipt_value'])->toBe('49.90')
        ->and($state['receipt_id'])->toBe('E123ABC')
        ->and(FlowReceipt::sole()->only(['status', 'amount_cents', 'payer']))->toBe(['status' => 'approved', 'amount_cents' => 4990, 'payer' => 'Ana Souza'])
        ->and($run['message']['attachments'][0]['type'])->toBe('image')
        ->and($run['conversation']['externalId'])->toStartWith('receipt:')
        ->and($conversation->messages()->where('message_type', MessageType::Info)->where('meta->info->code', 'flow_receipt_approved')->exists())->toBeTrue();
});

test('a receipt for less than expected gets another try, then the rejected branch', function () {
    [$conversation] = receiptFlow(['expected_amount' => '{{preco}}', 'max_attempts' => 2]);
    fakeReceiptHub(receiptReading(['amount' => '10.00', 'transaction_id' => null]));

    AiAgentFixtures::openWithWelcome($conversation);
    $flowState = FlowState::where('conversation_id', $conversation->id)->first();
    $flowState->update(['state_data' => array_merge($flowState->state_data, ['preco' => '49,90'])]);

    customerSendsReceipt($conversation, 'first');
    expect(receiptOutgoing($conversation))->toBe(['Envie o comprovante.', 'Não consegui confirmar. Envie de novo.']);

    customerSendsReceipt($conversation, 'second');

    expect(receiptOutgoing($conversation))->toBe(['Envie o comprovante.', 'Não consegui confirmar. Envie de novo.', 'Vou chamar alguém.'])
        ->and(FlowReceipt::pluck('reason')->all())->toBe(['amount_mismatch', 'amount_mismatch'])
        ->and($flowState->fresh()->state_data['receipt_reason'])->toBe('amount_mismatch')
        ->and($conversation->messages()->where('meta->info->code', 'flow_receipt_rejected')->count())->toBe(1);
});

test('what the model reports decides the reason', function (array $reading, array $data, string $reason) {
    [$conversation] = receiptFlow($data + ['max_attempts' => 1]);
    fakeReceiptHub(receiptReading($reading));

    AiAgentFixtures::openWithWelcome($conversation);
    customerSendsReceipt($conversation);

    expect(FlowReceipt::sole()->reason)->toBe($reason)
        ->and(receiptOutgoing($conversation))->toContain('Vou chamar alguém.');
})->with([
    'not a payment document' => [['is_receipt' => false], [], 'not_a_receipt'],
    'a payment still pending' => [['completed' => false], [], 'not_completed'],
    'no readable amount' => [['amount' => null], [], 'amount_unreadable'],
    'paid to somebody else' => [['recipient_matches' => false], ['expected_recipient' => 'Loja Aurora'], 'recipient_mismatch'],
]);

test('the same file or the same transaction is not accepted twice', function () {
    [$conversation, $node] = receiptFlow();
    fakeReceiptHub(receiptReading());

    AiAgentFixtures::openWithWelcome($conversation);
    customerSendsReceipt($conversation, 'same-bytes');

    // A second conversation in the same workspace, parked on the same node.
    $other = Conversation::create([
        'contact_id' => $conversation->contact_id, 'connection_id' => $conversation->connection_id,
        'external_id' => '5511888888888', 'status' => ConversationStatus::Pending,
    ]);
    AiAgentFixtures::openWithWelcome($other);

    customerSendsReceipt($other, 'same-bytes');
    customerSendsReceipt($other, 'other-bytes-same-transaction');

    expect(FlowReceipt::where('conversation_id', $other->id)->pluck('reason')->all())->toBe(['duplicate', 'duplicate'])
        // The repeated file was turned down without paying for a second read.
        ->and(count(AiAgentFixtures::hubRuns()))->toBe(2);
});

test('an approved receipt reports the purchase to the chosen pixels with its amount', function () {
    Queue::fake([SendPixelEvent::class]);

    [$conversation, $node] = receiptFlow();
    $pixel = IntegrationFixtures::integration($conversation->connection->tenant, IntegrationProvider::MetaPixel, ['webhook_token' => str_repeat('t', 40)]);
    $node->update(['data' => array_merge($node->data, ['pixel_integration_ids' => [$pixel->id]])]);

    fakeReceiptHub(receiptReading(['amount' => '1.234,50']));
    AiAgentFixtures::openWithWelcome($conversation);
    customerSendsReceipt($conversation);

    Queue::assertPushed(SendPixelEvent::class, fn (SendPixelEvent $job) => $job->integrationId === $pixel->id
        && $job->event['event'] === 'purchase'
        && (float) $job->event['value'] === 1234.5
        && $job->event['event_id'] === 'receipt-'.FlowReceipt::sole()->id);
});

test('the check waits for the download, and words are not an attempt', function () {
    [$conversation, $node] = receiptFlow();
    fakeReceiptHub(receiptReading());
    AiAgentFixtures::openWithWelcome($conversation);

    // "já paguei" gets the request once more, and only once.
    foreach (['já paguei', 'oi?'] as $text) {
        $conversation->messages()->create(['external_id' => uniqid(), 'sender_type' => SenderType::Incoming, 'message_type' => MessageType::Text, 'body' => $text, 'sent_at' => now()]);
        (new FlowExecutor)->resumeFlow($conversation->fresh(), $text);
    }

    expect(receiptOutgoing($conversation))->toBe(['Envie o comprovante.', 'Envie o comprovante.'])
        ->and(FlowReceipt::count())->toBe(0);

    // The picture is announced before its bytes are here.
    $message = AiAgentFixtures::incomingMedia($conversation, MessageType::Image, null, null, AttachmentStatus::Pending);
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '');
    expect(AiAgentFixtures::hubRuns())->toBe([]);

    Storage::disk('local')->put('media/9/comprovante.jpg', 'bytes');
    $message->forceFill(['attachment' => 'media/9/comprovante.jpg', 'attachment_status' => null])->save();
    (new FlowExecutor)->resumeAfterMedia($message->fresh());

    expect(FlowReceipt::sole()->status)->toBe('approved');
});

test('a hub failure leaves through rejected without blaming the customer', function () {
    [$conversation] = receiptFlow(['max_attempts' => 3]);
    fakeReceiptHub('Desculpe, não entendi.');

    AiAgentFixtures::openWithWelcome($conversation);
    customerSendsReceipt($conversation);

    expect(FlowReceipt::sole()->reason)->toBe('ai_unavailable')
        ->and(receiptOutgoing($conversation))->toBe(['Envie o comprovante.', 'Vou chamar alguém.']);
});

test('a node with no AI agent asks for nothing and leaves through rejected', function () {
    [$conversation] = receiptFlow(['ai_hub_agent_id' => null]);
    fakeReceiptHub('');

    AiAgentFixtures::openWithWelcome($conversation);

    expect(receiptOutgoing($conversation))->toBe(['Vou chamar alguém.'])
        ->and(FlowReceipt::count())->toBe(0);
});
