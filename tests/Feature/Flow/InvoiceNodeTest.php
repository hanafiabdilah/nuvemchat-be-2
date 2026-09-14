<?php

use App\Enums\Flow\FlowInvoiceStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\FlowInvoice;
use App\Models\FlowState;
use App\Models\Integration;
use App\Models\Message;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\InvoiceNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

const INVNODE_PDF_BYTES = "%PDF-1.4\n% nota fiscal de teste\n%%EOF";

const INVNODE_RAW_REJECTION = 'Inscrição municipal do prestador não confere com o cadastro da prefeitura de São Paulo';

function invnodePath(Request $request): string
{
    return (string) parse_url($request->url(), PHP_URL_PATH);
}

/**
 * Spedy and the WhatsApp channel behind one fake, driven by a mutable array so
 * a test can say "now the prefeitura authorized it" and mean it.
 *
 * @param  array<string, mixed>  $spedy
 */
function invnodeFakeSpedy(array &$spedy): void
{
    Http::fake(function (Request $request) use (&$spedy) {
        $url = $request->url();
        $path = invnodePath($request);
        $method = $request->method();

        if (str_contains($url, 'graph.facebook.com')) {
            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]);
        }

        if (! str_contains($url, 'spedy.com.br')) {
            return Http::response([], 404);
        }

        return match (true) {
            $path === '/v1/service-invoices/inv_1/pdf' => Http::response(INVNODE_PDF_BYTES, 200, ['Content-Type' => 'application/pdf']),
            $path === '/v1/service-invoices/inv_1' && $method === 'GET' => Http::response(array_filter([
                'id' => 'inv_1',
                'status' => $spedy['status'] ?? 'enqueued',
                'number' => $spedy['number'] ?? null,
                'authorization' => ($spedy['status'] ?? null) === 'authorized' ? ['date' => '2026-09-14T10:00:00'] : null,
                'processingDetail' => isset($spedy['detail']) ? ['message' => $spedy['detail'], 'code' => 'E160'] : null,
            ], fn ($value) => $value !== null)),
            $path === '/v1/service-invoices' && $method === 'GET' => Http::response(['items' => []]),
            $path === '/v1/service-invoices' && $method === 'POST' => (function () use (&$spedy, $request) {
                $spedy['creates'] = ($spedy['creates'] ?? 0) + 1;
                $spedy['body'] = $request->data();

                return Http::response(['id' => 'inv_1', 'status' => 'enqueued', 'integrationId' => $request['integrationId']]);
            })(),
            default => Http::response(['message' => 'Not found'], 404),
        };
    });
}

/**
 * start → invoice → (issued) "Nota enviada!" · (failed) "Não conseguimos emitir a nota."
 *
 * @param  array<string, mixed>  $data
 * @return array{0: Conversation, 1: Integration, 2: \App\Models\FlowNode}
 */
function invnodeFixture(array $data = []): array
{
    $tenant = Fx::tenant();
    $integration = Fx::integration($tenant, IntegrationProvider::Spedy);
    $flow = Fx::flow($tenant);

    $invoice = Fx::node($flow, NodeType::Invoice, array_merge([
        'integration_id' => $integration->id,
        'amount' => '49,90',
        'description' => 'Consultoria online',
        'customer_document' => '123.456.789-09',
        'wait_minutes' => 30,
        'send_pdf' => true,
        'message' => 'Sua nota fiscal nº {{invoice_number}} foi emitida. Segue o PDF:',
    ], $data));

    $issued = Fx::say($flow, 'Nota enviada!', 560, -100);
    $failed = Fx::say($flow, 'Não conseguimos emitir a nota.', 560, 100);

    Fx::edge(Fx::start($flow), $invoice);
    Fx::edge($invoice, $issued, InvoiceNodes::BRANCH_ISSUED);
    Fx::edge($invoice, $failed, InvoiceNodes::BRANCH_FAILED);

    return [Fx::conversation($tenant, $flow), $integration, $invoice];
}

function invnodeWebhook(Integration $integration, FlowInvoice $invoice): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/webhook/integrations/spedy/{$integration->webhook_token}", [
        'event' => 'invoice.status_changed',
        'data' => ['id' => 'inv_1', 'integrationId' => $invoice->reference, 'status' => 'authorized'],
    ]);
}

/** @return \Illuminate\Support\Collection<int, Message> */
function invnodeDocuments(Conversation $conversation)
{
    return Message::where('conversation_id', $conversation->id)
        ->where('sender_type', SenderType::Outgoing)
        ->where('message_type', MessageType::Document)
        ->get();
}

test('requests the invoice at Spedy, notes it and parks the flow', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation, $integration, $node] = invnodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    $invoice = FlowInvoice::sole();

    expect($invoice->status)->toBe(FlowInvoiceStatus::Processing)
        ->and($invoice->provider_invoice_id)->toBe('inv_1')
        ->and($invoice->amount_cents)->toBe(4990)
        ->and($invoice->customer_document)->toBe('12345678909')
        ->and($invoice->integration_id)->toBe($integration->id)
        // Nothing reaches the customer before the authority answers.
        ->and(Fx::sentTexts($conversation))->toBe([])
        ->and(invnodeDocuments($conversation))->toHaveCount(0)
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_REQUESTED))->toHaveCount(1);

    // Looked up by our reference first, then filed under it.
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && invnodePath($request) === '/v1/service-invoices'
        && str_contains($request->url(), 'integrationId='.$invoice->reference));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://api.spedy.com.br/v1/service-invoices')
        && ($request->header('X-Api-Key')[0] ?? null) === Fx::CREDENTIALS['spedy']['api_key']);

    expect($spedy['body']['integrationId'])->toBe($invoice->reference)
        ->and($spedy['body']['receiver']['federalTaxNumber'])->toBe('12345678909')
        ->and($spedy['body']['receiver']['name'])->toBe('Maria Souza')
        ->and((float) $spedy['body']['total']['invoiceAmount'])->toBe(49.9)
        ->and($spedy['body']['federalServiceCode'])->toBe('1.06');

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($state->current_node_id)->toBe($node->id)
        ->and($state->state_data[InvoiceNodes::stateKey($node->id)])->toBe($invoice->id)
        ->and($state->state_data['invoice_status'])->toBe('processing');
});

test('an authorization confirmed through the webhook sends the message and the PDF and takes issued', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation, $integration] = invnodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $invoice = FlowInvoice::sole();

    $spedy['status'] = 'authorized';
    $spedy['number'] = '1234';

    invnodeWebhook($integration, $invoice)->assertOk();

    $invoice->refresh();
    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($invoice->status)->toBe(FlowInvoiceStatus::Issued)
        ->and($invoice->number)->toBe('1234')
        ->and($invoice->issued_at)->not->toBeNull()
        ->and(Fx::sentTexts($conversation))->toBe([
            'Sua nota fiscal nº 1234 foi emitida. Segue o PDF:',
            'Nota enviada!',
        ])
        ->and(invnodeDocuments($conversation))->toHaveCount(1)
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_ISSUED))->toHaveCount(1)
        ->and($state->state_data['invoice_status'])->toBe('issued')
        ->and($state->state_data['invoice_number'])->toBe('1234')
        ->and($state->state_data['invoice_pdf_url'])->toContain("/flow-invoices/{$invoice->reference}/nota-fiscal-1234.pdf")
        ->and($state->state_data['invoice_pdf_url'])->toContain('signature=')
        ->and($state->state_data)->not->toHaveKey(InvoiceNodes::stateKey($invoice->flow_node_id));

    // The document goes out by our signed link, never Spedy's.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
        && ($request['type'] ?? null) === 'document'
        && str_contains((string) ($request['document']['link'] ?? ''), "/flow-invoices/{$invoice->reference}/nota-fiscal-1234.pdf"));

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && invnodePath($request) === '/v1/service-invoices/inv_1');
});

test('a forged webhook cannot issue anything', function () {
    // The body says authorized; Spedy, asked with the workspace's key, does not.
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation, $integration] = invnodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $invoice = FlowInvoice::sole();

    invnodeWebhook($integration, $invoice)->assertOk();

    expect($invoice->fresh()->status)->toBe(FlowInvoiceStatus::Processing)
        ->and(Fx::sentTexts($conversation))->toBe([])
        ->and(invnodeDocuments($conversation))->toHaveCount(0);
});

test('a rejected invoice takes the failed branch, in our words', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation, $integration] = invnodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $invoice = FlowInvoice::sole();

    $spedy['status'] = 'rejected';
    $spedy['detail'] = INVNODE_RAW_REJECTION;

    invnodeWebhook($integration, $invoice)->assertOk();

    $invoice->refresh();
    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($invoice->status)->toBe(FlowInvoiceStatus::Failed)
        ->and($invoice->failure_reason)->not->toBeEmpty()
        ->and($invoice->failure_reason)->not->toContain('Inscrição municipal')
        ->and($invoice->failure_reason)->not->toContain('E160')
        ->and(Fx::sentTexts($conversation))->toBe(['Não conseguimos emitir a nota.'])
        ->and(invnodeDocuments($conversation))->toHaveCount(0)
        ->and($state->state_data['invoice_error'])->toBe($invoice->failure_reason)
        ->and($state->state_data['invoice_pdf_url'])->toBeNull();

    $note = Fx::notes($conversation, InvoiceNodes::INFO_FAILED)->sole();
    expect($note->meta['info']['params']['reason'])->toBe($invoice->failure_reason)
        ->and(json_encode($note->meta, JSON_UNESCAPED_UNICODE))->not->toContain('Inscrição municipal');
});

test('an invoice without the customer CPF/CNPJ fails without calling Spedy', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    // A variable nobody filled in resolves to an empty string.
    [$conversation] = invnodeFixture(['customer_document' => '{{cpf}}']);
    (new FlowExecutor)->startFlow($conversation);

    $invoice = FlowInvoice::sole();

    expect($invoice->status)->toBe(FlowInvoiceStatus::Failed)
        ->and($invoice->failure_reason)->toContain('CPF/CNPJ')
        ->and(Fx::sentTexts($conversation))->toBe(['Não conseguimos emitir a nota.'])
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_FAILED))->toHaveCount(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'spedy.com.br'));
});

test('the customer writing while the invoice is processing does not request a second one', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation] = invnodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'cadê minha nota?');
    (new FlowExecutor)->resumeFlow($conversation->fresh(), '123.456.789-09');

    expect(FlowInvoice::count())->toBe(1)
        ->and($spedy['creates'])->toBe(1)
        ->and(Fx::sentTexts($conversation))->toBe([]);
});

test('past the deadline the flow is released down failed while the invoice stays processing', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation] = invnodeFixture(['wait_minutes' => 5]);
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(10)->minutes();
    $this->artisan('flow-invoices:sync')->assertSuccessful();

    $invoice = FlowInvoice::sole();
    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($invoice->status)->toBe(FlowInvoiceStatus::Processing)
        ->and($invoice->meta['released_at'] ?? null)->not->toBeNull()
        ->and(Fx::sentTexts($conversation))->toBe(['Não conseguimos emitir a nota.'])
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_STILL_PROCESSING))->toHaveCount(1)
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_FAILED))->toHaveCount(0)
        ->and($state->state_data['invoice_error'])->toBe('A nota fiscal ainda está em processamento na prefeitura.');

    // A second pass does not release it twice.
    $this->travel(20)->minutes();
    $this->artisan('flow-invoices:sync')->assertSuccessful();

    expect(Fx::notes($conversation, InvoiceNodes::INFO_STILL_PROCESSING))->toHaveCount(1)
        ->and(Fx::sentTexts($conversation))->toBe(['Não conseguimos emitir a nota.']);
});

test('an authorization after the flow was released is recorded as late, never replayed', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation, $integration] = invnodeFixture(['wait_minutes' => 5]);
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(10)->minutes();
    $this->artisan('flow-invoices:sync')->assertSuccessful();

    $spedy['status'] = 'authorized';
    $spedy['number'] = '987';

    $this->travel(30)->minutes();
    invnodeWebhook($integration, FlowInvoice::sole())->assertOk();

    $invoice = FlowInvoice::sole();

    expect($invoice->status)->toBe(FlowInvoiceStatus::Issued)
        ->and($invoice->number)->toBe('987')
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_ISSUED_LATE))->toHaveCount(1)
        ->and(Fx::notes($conversation, InvoiceNodes::INFO_ISSUED))->toHaveCount(0)
        ->and(Fx::sentTexts($conversation))->toBe(['Não conseguimos emitir a nota.'])
        ->and(invnodeDocuments($conversation))->toHaveCount(0);
});

test('the document route streams the PDF from Spedy behind a signed link', function () {
    $spedy = [];
    invnodeFakeSpedy($spedy);

    [$conversation, $integration] = invnodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    $spedy['status'] = 'authorized';
    $spedy['number'] = '1234';
    invnodeWebhook($integration, FlowInvoice::sole())->assertOk();

    $invoice = FlowInvoice::sole();

    $response = $this->get($invoice->documentUrl('pdf'));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toBe(INVNODE_PDF_BYTES);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && invnodePath($request) === '/v1/service-invoices/inv_1/pdf'
        && ($request->header('X-Api-Key')[0] ?? null) === Fx::CREDENTIALS['spedy']['api_key']);

    // Unsigned, it is nobody's business.
    $this->get("/flow-invoices/{$invoice->reference}/nota-fiscal-1234.pdf")->assertForbidden();
});

test('an invoice node only saves pointing at this workspace\'s invoice integrations', function () {
    $user = Fx::user();
    $tenant = $user->tenant;
    $flow = Fx::flow($tenant);
    $start = Fx::start($flow);

    $payment = Fx::integration($tenant, IntegrationProvider::OpenPix);
    $foreign = Fx::integration(Fx::tenant(), IntegrationProvider::Spedy);
    $own = Fx::integration($tenant, IntegrationProvider::Spedy);

    $payload = fn (int $integrationId) => [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'nf', 'type' => 'invoice', 'data' => ['integration_id' => $integrationId, 'amount' => '10', 'customer_document' => '{{cpf}}'], 'position_x' => 280, 'position_y' => 0],
            ['id' => 'ok', 'type' => 'message', 'data' => ['body' => 'ok', 'message_type' => 'text'], 'position_x' => 560, 'position_y' => 0],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'nf', 'condition_value' => null],
            ['source_node_id' => 'nf', 'target_node_id' => 'ok', 'condition_value' => 'issued'],
        ],
    ];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($payment->id))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.integration_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($foreign->id))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.integration_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($own->id))
        ->assertOk();
});
