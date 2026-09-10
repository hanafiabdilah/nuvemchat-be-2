<?php

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Flow\FlowStateStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Models\Conversation;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\Integration;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\FlowPaymentService;
use App\Services\Flow\PaymentNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

/**
 * Every gateway the suite talks to, driven by one mutable array so a test can
 * say "now the gateway says it was paid" and mean it.
 *
 * @param  array<string, mixed>  $gateway
 */
function paymentNodeGateways(array &$gateway): void
{
    Http::fake(function (Request $request) use (&$gateway) {
        $url = $request->url();

        if (str_contains($url, 'graph.facebook.com')) {
            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]);
        }

        if (str_contains($url, 'api.openpix.com.br/api/v1/charge')) {
            if ($request->method() === 'POST') {
                $gateway['openpix_creates'] = ($gateway['openpix_creates'] ?? 0) + 1;

                if (($gateway['openpix_create_status'] ?? 200) !== 200) {
                    return Http::response(['error' => 'appID inválido'], $gateway['openpix_create_status']);
                }

                return Http::response(['charge' => [
                    'correlationID' => $request['correlationID'],
                    'globalID' => 'Q2hhcmdlOjE=',
                    'status' => 'ACTIVE',
                    'value' => $request['value'],
                    'brCode' => Fx::PIX_CODE,
                    'paymentLinkUrl' => 'https://openpix.com.br/pay/abc123',
                    'expiresDate' => now()->addHour()->toIso8601String(),
                ]]);
            }

            return Http::response(['charge' => [
                'status' => $gateway['openpix_status'] ?? 'ACTIVE',
                'paidAt' => now()->toIso8601String(),
            ]]);
        }

        if (str_contains($url, 'api.mercadopago.com/checkout/preferences')) {
            $gateway['mp_reference'] = $request['external_reference'];

            return Http::response([
                'id' => 'pref-1',
                'init_point' => 'https://mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
            ], 201);
        }

        if (str_contains($url, 'api.mercadopago.com/v1/payments/search')) {
            return Http::response(['results' => [[
                'id' => 1234567,
                'status' => $gateway['mp_status'] ?? 'pending',
                'status_detail' => $gateway['mp_status_detail'] ?? null,
                'date_approved' => now()->toIso8601String(),
            ]]]);
        }

        if (str_contains($url, 'api.mercadopago.com/v1/payments/1234567')) {
            return Http::response(['id' => 1234567, 'external_reference' => $gateway['mp_reference'] ?? null]);
        }

        if (str_contains($url, 'api.mercadopago.com/v1/payments')) {
            $gateway['mp_reference'] = $request['external_reference'];
            $gateway['mp_idempotency_key'] = $request->header('X-Idempotency-Key')[0] ?? null;

            return Http::response([
                'id' => 1234567,
                'status' => 'pending',
                'status_detail' => 'pending_waiting_transfer',
                'date_of_expiration' => now()->addHour()->format('Y-m-d\TH:i:s.vP'),
                'point_of_interaction' => ['transaction_data' => [
                    'qr_code' => Fx::PIX_CODE,
                    'ticket_url' => 'https://www.mercadopago.com.br/payments/1234567/ticket',
                ]],
            ], 201);
        }

        return Http::response([], 404);
    });
}

/**
 * start → payment → (paid) "Pagamento confirmado!" · (failed) "Não recebemos o pagamento."
 *
 * The two branch messages are how each test reads which way the flow went —
 * the same thing the author decides between when they wire the two handles.
 *
 * @param  array<string, mixed>  $data
 * @return array{0: Conversation, 1: Integration, 2: \App\Models\FlowNode}
 */
function paymentNodeFixture(array $data = [], IntegrationProvider $provider = IntegrationProvider::OpenPix): array
{
    $tenant = Fx::tenant();
    $integration = Fx::integration($tenant, $provider);
    $flow = Fx::flow($tenant);

    $payment = Fx::node($flow, NodeType::Payment, array_merge([
        'integration_id' => $integration->id,
        'method' => 'pix',
        'amount' => '49,90',
        'description' => 'Pedido 123',
        'expires_in_minutes' => 60,
        'message' => 'Segue o Pix de {{payment_amount}} para finalizar:',
        'send_qr_code' => true,
        'send_copy_paste' => true,
    ], $data));

    $paid = Fx::say($flow, 'Pagamento confirmado!', 560, -100);
    $failed = Fx::say($flow, 'Não recebemos o pagamento.', 560, 100);

    Fx::edge(Fx::start($flow), $payment);
    Fx::edge($payment, $paid, PaymentNodes::BRANCH_PAID);
    Fx::edge($payment, $failed, PaymentNodes::BRANCH_FAILED);

    return [Fx::conversation($tenant, $flow), $integration, $payment];
}

function paymentWebhook(Integration $integration, array $payload, string $query = ''): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/webhook/integrations/{$integration->provider->value}/{$integration->webhook_token}{$query}", $payload);
}

test('issues an OpenPix charge, sends the Pix and parks the flow', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation, $integration, $node] = paymentNodeFixture();

    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Pending)
        ->and($payment->amount_cents)->toBe(4990)
        ->and($payment->pix_code)->toBe(Fx::PIX_CODE)
        ->and($payment->integration_id)->toBe($integration->id);

    // What the customer got: the message with the amount filled in, then the
    // code on its own — and nothing from either branch yet.
    expect(Fx::sentTexts($conversation))->toBe([
        'Segue o Pix de R$ 49,90 para finalizar:',
        Fx::PIX_CODE,
    ]);

    // The QR goes out as an image the channel fetches from our signed route.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
        && str_contains(json_encode($request->data(), JSON_UNESCAPED_SLASHES), '/flow-payments/'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openpix.com.br/api/v1/charge')
        && $request['correlationID'] === $payment->reference
        && $request['value'] === 4990);

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($state->current_node_id)->toBe($node->id)
        ->and($state->state_data[PaymentNodes::stateKey($node->id)])->toBe($payment->id)
        ->and($state->state_data['payment_link'])->toBe('https://openpix.com.br/pay/abc123')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_CREATED))->toHaveCount(1);
});

test('a webhook confirming the payment takes the paid branch', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation, $integration] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $gateway['openpix_status'] = 'COMPLETED';

    paymentWebhook($integration, [
        'event' => 'OPENPIX:CHARGE_COMPLETED',
        'charge' => ['correlationID' => $payment->reference, 'status' => 'COMPLETED'],
    ])->assertOk();

    $payment->refresh();
    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!')
        ->and(Fx::sentTexts($conversation))->not->toContain('Não recebemos o pagamento.')
        ->and($state->state_data['payment_status'])->toBe('paid')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID))->toHaveCount(1);
});

test('a forged webhook cannot mark anything paid', function () {
    // The body says COMPLETED; the gateway, asked with the workspace's own key,
    // says it is still open. The gateway is the one believed.
    $gateway = ['openpix_status' => 'ACTIVE'];
    paymentNodeGateways($gateway);

    [$conversation, $integration] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    paymentWebhook($integration, [
        'event' => 'OPENPIX:CHARGE_COMPLETED',
        'charge' => ['correlationID' => $payment->reference, 'status' => 'COMPLETED'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(FlowPaymentStatus::Pending)
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!');
});

test('the same payment confirmed twice resumes the flow once', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation, $integration] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $gateway['openpix_status'] = 'COMPLETED';
    $payload = ['charge' => ['correlationID' => $payment->reference]];

    paymentWebhook($integration, $payload)->assertOk();
    paymentWebhook($integration, $payload)->assertOk();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    $confirmations = array_filter(Fx::sentTexts($conversation), fn ($body) => $body === 'Pagamento confirmado!');

    expect($confirmations)->toHaveCount(1)
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID))->toHaveCount(1);
});

test('the customer writing while it waits does not create a second charge', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    (new FlowExecutor)->resumeFlow($conversation->fresh(), 'já paguei');

    expect(FlowPayment::count())->toBe(1)
        ->and($gateway['openpix_creates'])->toBe(1)
        ->and(Fx::sentTexts($conversation))->not->toContain('Não recebemos o pagamento.');
});

test('an unpaid charge takes the failed branch once it expires', function () {
    $gateway = ['openpix_status' => 'EXPIRED'];
    paymentNodeGateways($gateway);

    [$conversation] = paymentNodeFixture(['expires_in_minutes' => 30]);
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Expired)
        ->and(Fx::sentTexts($conversation))->toContain('Não recebemos o pagamento.')
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_EXPIRED))->toHaveCount(1);
});

test('a payment made in the last second is still paid when the deadline check runs', function () {
    // The webhook never arrived; the expiry pass asks before giving up.
    $gateway = ['openpix_status' => 'COMPLETED'];
    paymentNodeGateways($gateway);

    [$conversation] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Paid)
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!');
});

test('a key the gateway refuses takes the failed branch at once, in our words', function () {
    $gateway = ['openpix_create_status' => 401];
    paymentNodeGateways($gateway);

    [$conversation, $integration] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Failed)
        ->and($payment->failure_reason)->toContain('OpenPix')
        ->and($payment->failure_reason)->not->toContain('appID inválido')
        ->and(Fx::sentTexts($conversation))->toBe(['Não recebemos o pagamento.'])
        ->and($integration->fresh()->last_error)->not->toBeNull();

    $note = Fx::notes($conversation, PaymentNodes::INFO_FAILED)->sole();
    expect($note->meta['info']['params']['reason'])->toBe($payment->failure_reason);
});

test('an amount that is not one never reaches the gateway', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    // A variable nobody filled in resolves to an empty string.
    [$conversation] = paymentNodeFixture(['amount' => '{{valor}}']);
    (new FlowExecutor)->startFlow($conversation);

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Failed)
        ->and($gateway['openpix_creates'] ?? 0)->toBe(0)
        ->and(Fx::sentTexts($conversation))->toBe(['Não recebemos o pagamento.']);
});

test('a Mercado Pago Pix is created idempotently and confirmed through its notification', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation, $integration] = paymentNodeFixture([], IntegrationProvider::MercadoPago);
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Pending)
        ->and($gateway['mp_reference'])->toBe($payment->reference)
        ->and($gateway['mp_idempotency_key'])->toBe($payment->reference)
        ->and($payment->payment_url)->toBe('https://www.mercadopago.com.br/payments/1234567/ticket');

    // Mercado Pago only points at the payment; the reference is read back.
    $gateway['mp_status'] = 'approved';

    paymentWebhook($integration, ['type' => 'payment', 'data' => ['id' => '1234567']], '?type=payment&data.id=1234567')
        ->assertOk();

    expect($payment->fresh()->status)->toBe(FlowPaymentStatus::Paid)
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!');
});

test('a checkout link always reaches the customer', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    // `send_link` off, and the message never mentions the link: a checkout the
    // customer never receives cannot be paid, so it goes out anyway.
    [$conversation] = paymentNodeFixture([
        'method' => 'checkout',
        'send_link' => false,
        'message' => 'Finalize seu pedido:',
    ], IntegrationProvider::MercadoPago);

    (new FlowExecutor)->startFlow($conversation);

    expect(Fx::sentTexts($conversation)[0])
        ->toBe("Finalize seu pedido:\n\nhttps://mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1");
});

test('a payment settled after an agent took over is recorded but not replayed', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation, $integration] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    // An agent accepts the conversation; the observer stops the flow.
    $conversation->fresh()->forceFill(['status' => ConversationStatus::Active])->save();

    $gateway['openpix_status'] = 'COMPLETED';
    paymentWebhook($integration, ['charge' => ['correlationID' => $payment->reference]])->assertOk();

    expect($payment->fresh()->status)->toBe(FlowPaymentStatus::Paid)
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID))->toHaveCount(1)
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!')
        ->and(FlowState::where('conversation_id', $conversation->id)->sole()->status)->toBe(FlowStateStatus::Stopped);
});

test('money that arrives after the charge expired is recorded as late, never replayed', function () {
    $gateway = ['openpix_status' => 'EXPIRED'];
    paymentNodeGateways($gateway);

    [$conversation] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    $payment = FlowPayment::sole();
    app(FlowPaymentService::class)->settle($payment, FlowPaymentStatus::Paid);

    $payment->refresh();

    expect($payment->status)->toBe(FlowPaymentStatus::Expired)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->meta['paid_late'])->toBeTrue()
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID_LATE))->toHaveCount(1)
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!');
});

test('the QR image is drawn from the Pix code behind a signed link', function () {
    $gateway = [];
    paymentNodeGateways($gateway);

    [$conversation] = paymentNodeFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $response = $this->get($payment->qrImageUrl());

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr($response->getContent(), 1, 3))->toBe('PNG');

    // Unsigned, it is nobody's business.
    $this->get("/flow-payments/{$payment->reference}/qr.png")->assertForbidden();
});

test('amounts are read the way people type them', function (string $typed, ?int $cents) {
    expect(PaymentNodes::parseAmount($typed))->toBe($cents);
})->with([
    ['49,90', 4990],
    ['49.90', 4990],
    ['49,9', 4990],
    ['10', 1000],
    ['1.500', 150000],
    ['R$ 1.234,56', 123456],
    ['1,234.56', 123456],
    ['0,50', 50],
    ['0', null],
    ['', null],
    ['abc', null],
    ['-10', null],
]);

test('a payment node only saves pointing at this workspace\'s payment integrations', function () {
    $user = Fx::user();
    $tenant = $user->tenant;
    $flow = Fx::flow($tenant);
    $start = Fx::start($flow);

    $pixel = Fx::integration($tenant, IntegrationProvider::MetaPixel);
    $foreign = Fx::integration(Fx::tenant(), IntegrationProvider::OpenPix);
    $own = Fx::integration($tenant, IntegrationProvider::OpenPix);

    $payload = fn (int $integrationId) => [
        'nodes' => [
            ['id' => (string) $start->id, 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
            ['id' => 'pay', 'type' => 'payment', 'data' => ['integration_id' => $integrationId, 'amount' => '10'], 'position_x' => 280, 'position_y' => 0],
            ['id' => 'ok', 'type' => 'message', 'data' => ['body' => 'ok', 'message_type' => 'text'], 'position_x' => 560, 'position_y' => 0],
        ],
        'edges' => [
            ['source_node_id' => (string) $start->id, 'target_node_id' => 'pay', 'condition_value' => null],
            ['source_node_id' => 'pay', 'target_node_id' => 'ok', 'condition_value' => 'paid'],
        ],
    ];

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($pixel->id))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.integration_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($foreign->id))
        ->assertUnprocessable()->assertJsonValidationErrors('nodes.1.data.integration_id');

    $this->actingAs($user, 'sanctum')->postJson("/api/flows/{$flow->id}/save", $payload($own->id))
        ->assertOk();
});
