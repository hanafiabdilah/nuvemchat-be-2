<?php

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Exceptions\UpstreamServiceException;
use App\Models\Conversation;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\Integration;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\PaymentNodes;
use App\Services\Integrations\IntegrationDrivers;
use App\Services\Integrations\Payments\ChargeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

const XPGW_SANDBOX_ID = 'xpagsandbox_00000000';

function xpgwPath(Request $request): string
{
    return (string) parse_url($request->url(), PHP_URL_PATH);
}

/**
 * XPag and the WhatsApp channel behind one fake, driven by a mutable array so
 * a test can say "now XPag says it was paid" and mean it.
 *
 * @param  array<string, mixed>  $gateway
 */
function xpgwFake(array &$gateway): void
{
    Http::fake(function (Request $request) use (&$gateway) {
        $url = $request->url();
        $path = xpgwPath($request);
        $method = $request->method();

        if (str_contains($url, 'graph.facebook.com')) {
            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]);
        }

        if (! str_contains($url, 'api.xpag.global')) {
            return Http::response([], 404);
        }

        $refusal = fn (int $status, string $code, string $error) => Http::response(
            ['ok' => false, 'error' => $error, 'error_code' => $code],
            $status,
            ['X-App-Error-Code' => $code],
        );

        return match (true) {
            $path === '/balance' => match ($gateway['balance'] ?? 'ok') {
                'no_permission' => $refusal(403, 'permission_denied', 'Esta chave não tem permissão para esta rota.'),
                'inactive' => $refusal(403, 'key_inactive', 'Chave inativa.'),
                'invalid' => $refusal(401, 'invalid_credentials', 'Credenciais inválidas.'),
                default => Http::response([
                    'ok' => true,
                    'balances' => ['BRL' => ['available' => 1530.75, 'blocked' => 200.00]],
                    'blocked_currencies' => [],
                    'updated_at' => '2026-07-24 12:00:00',
                ]),
            },
            $path === '/cashin' && $method === 'POST' => (function () use (&$gateway, $request, $refusal) {
                $gateway['creates'] = ($gateway['creates'] ?? 0) + 1;
                $gateway['body'] = $request->data();

                return match ($gateway['create'] ?? 'ok') {
                    'no_permission' => $refusal(403, 'permission_denied', 'Esta chave não tem permissão para esta rota.'),
                    'kyc' => $refusal(422, 'kyc_required', 'Conta precisa concluir o KYC.'),
                    // A refusal dressed as a success.
                    'soft_refusal' => Http::response(['ok' => false, 'error' => 'Valor abaixo do mínimo.', 'error_code' => 'amount_below_min']),
                    default => Http::response([
                        'ok' => true,
                        'qr_img' => 'data:image/png;base64,iVBORw0KGgo=',
                        'copyPaste' => Fx::PIX_CODE,
                        'code' => Fx::PIX_CODE,
                        'request_number' => 'pr_1',
                        'transaction_id' => 'pr_1',
                    ]),
                };
            })(),
            $path === '/consult-transaction' => (function () use (&$gateway, $request) {
                $gateway['consulted'][] = $request->data();

                if (($gateway['consult'] ?? null) === 'not_found') {
                    return Http::response(['ok' => false, 'error' => 'Transação não encontrada.', 'error_code' => 'not_found'], 404);
                }

                if (isset($request->data()['external_id'])) {
                    return Http::response([
                        'ok' => true,
                        'type' => 'cashin',
                        'count' => 1,
                        'payments' => [[
                            'id' => 'pr_1',
                            'status' => $gateway['status'] ?? 'pending',
                            'amount' => 49.9,
                            'transaction_id' => 'pr_1',
                            'external_id' => $request->data()['external_id'],
                        ]],
                    ]);
                }

                return Http::response([
                    'ok' => true,
                    'type' => 'cashin',
                    'status' => $gateway['status'] ?? 'pending',
                    'amount' => 49.9,
                    'fee' => 0.5,
                    'request_number' => 'pr_1',
                    'transaction_id' => 'pr_1',
                    'e2e' => 'E123',
                    'provider' => 'XPag',
                    'updated_at' => '2026-11-10 12:00:00',
                ]);
            })(),
            default => $refusal(404, 'not_found', 'Rota não encontrada.'),
        };
    });
}

/**
 * start → payment → (paid) "Pagamento confirmado!" · (failed) "Não recebemos o pagamento."
 *
 * @param  array<string, mixed>  $data
 * @return array{0: Conversation, 1: Integration, 2: \App\Models\FlowNode}
 */
function xpgwPaymentFixture(array $data = []): array
{
    $tenant = Fx::tenant();
    $integration = Fx::integration($tenant, IntegrationProvider::XPag);
    $flow = Fx::flow($tenant);

    $payment = Fx::node($flow, NodeType::Payment, array_merge([
        'integration_id' => $integration->id,
        'method' => 'pix',
        'amount' => '49,90',
        'description' => 'Pedido 123',
        'expires_in_minutes' => 60,
        'message' => 'Segue o Pix de {{payment_amount}}:',
        'send_qr_code' => true,
        'send_copy_paste' => true,
        'payer_document' => '123.456.789-09',
    ], $data));

    $paid = Fx::say($flow, 'Pagamento confirmado!', 560, -100);
    $failed = Fx::say($flow, 'Não recebemos o pagamento.', 560, 100);

    Fx::edge(Fx::start($flow), $payment);
    Fx::edge($payment, $paid, PaymentNodes::BRANCH_PAID);
    Fx::edge($payment, $failed, PaymentNodes::BRANCH_FAILED);

    return [Fx::conversation($tenant, $flow), $integration, $payment];
}

// ─────────────────────────────  Connecting  ─────────────────────────────

test('connecting XPag checks the credentials against the balance route', function () {
    $gateway = [];
    xpgwFake($gateway);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'xpag',
        'name' => 'XPag',
        'credentials' => Fx::CREDENTIALS['xpag'],
    ])->assertCreated()
        ->assertJsonPath('data.category', 'payment')
        ->assertJsonPath('data.payment_methods', ['pix'])
        ->assertJsonPath('data.account.environment', 'production')
        // Nothing to register: every charge carries the URL itself.
        ->assertJsonPath('data.webhook.mode', 'per_payment');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xpag.global/balance'
        && ($request->header('X-Client-Id')[0] ?? null) === Fx::CREDENTIALS['xpag']['client_id']
        && ($request->header('X-Client-Secret')[0] ?? null) === Fx::CREDENTIALS['xpag']['client_secret']);

    expect(Integration::sole()->credential('client_secret'))->toBe(Fx::CREDENTIALS['xpag']['client_secret']);
});

test('a sandbox Client ID is shown as the sandbox', function () {
    $gateway = [];
    xpgwFake($gateway);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'xpag',
        'name' => 'XPag teste',
        'credentials' => ['client_id' => XPGW_SANDBOX_ID, 'client_secret' => '202620262026202620262026'],
    ])->assertCreated()->assertJsonPath('data.account.environment', 'sandbox');
});

test('a key without the balance permission still connects — charging does not need it', function () {
    $gateway = ['balance' => 'no_permission'];
    xpgwFake($gateway);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'xpag',
        'name' => 'XPag',
        'credentials' => Fx::CREDENTIALS['xpag'],
    ])->assertCreated();
});

test('a disabled key or wrong credentials are refused in our words, and nothing is saved', function (string $balance, string $expected) {
    $gateway = ['balance' => $balance];
    xpgwFake($gateway);

    $response = $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'xpag',
        'name' => 'XPag',
        'credentials' => Fx::CREDENTIALS['xpag'],
    ])->assertStatus(422)->assertJsonPath('code', 'integration_credentials_invalid');

    expect($response->json('message'))->toContain($expected)
        ->not->toContain('Chave inativa')
        ->not->toContain('Credenciais inválidas')
        ->and(Integration::count())->toBe(0);
})->with([
    'disabled key' => ['inactive', 'desativada'],
    'wrong credentials' => ['invalid', 'Client ID e o Client secret'],
]);

test('the catalog holds an XPag Pix to its 24-hour lifetime', function () {
    expect(IntegrationProvider::XPag->chargeWindowMinutes('pix'))->toBe([1440, 1440])
        ->and(IntegrationProvider::XPag->paymentMethods())->toBe(['pix'])
        ->and(IntegrationProvider::XPag->webhookMode())->toBe('per_request')
        ->and(IntegrationProvider::XPag->requiresPayerDocument())->toBeFalse();
});

// ─────────────────────────────  Charging  ─────────────────────────────

test('an XPag Pix is filed under our reference, sends the code and lasts 24 hours', function () {
    $gateway = [];
    xpgwFake($gateway);

    [$conversation, , $node] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Pending)
        ->and($payment->provider_payment_id)->toBe('pr_1')
        ->and($payment->pix_code)->toBe(Fx::PIX_CODE)
        ->and($payment->payment_url)->toBeNull()
        ->and(Fx::sentTexts($conversation))->toBe(['Segue o Pix de R$ 49,90:', Fx::PIX_CODE]);

    // The node asked for 60 minutes; the QR lives 24 hours at XPag and cannot
    // be closed earlier, so the deadline is the QR's.
    expect($payment->expires_at->diffInMinutes(now()->addDay(), true))->toBeLessThan(2);

    expect($gateway['body'])->toMatchArray([
        'currency' => 'BRL',
        'amount' => 49.9,
        'description' => 'Pedido 123',
        'external_id' => $payment->reference,
        'name' => 'Maria Souza',
        'document' => '12345678909',
    ])->and($gateway['body'])->not->toHaveKey('webhook_url');

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($state->state_data[PaymentNodes::stateKey($node->id)])->toBe($payment->id)
        ->and($gateway['creates'])->toBe(1);
});

test('the webhook address goes with the charge when the platform is on https', function () {
    URL::forceRootUrl('https://chat.example.com');
    URL::forceScheme('https');

    $gateway = [];
    xpgwFake($gateway);

    [$conversation, $integration] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);

    expect($gateway['body']['webhook_url'])->toBe($integration->webhookUrl())
        ->and($gateway['body']['webhook_url'])->toStartWith('https://chat.example.com/webhook/integrations/xpag/');
});

test('no CPF/CNPJ is fine — XPag does not require one', function () {
    $gateway = [];
    xpgwFake($gateway);

    [$conversation] = xpgwPaymentFixture(['payer_document' => '{{cpf}}']);
    (new FlowExecutor)->startFlow($conversation);

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Pending)
        ->and($gateway['body'])->not->toHaveKey('document');
});

test('a webhook pointing at our charge and a confirmed read-back takes the paid branch', function () {
    $gateway = [];
    xpgwFake($gateway);

    [$conversation, $integration] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $gateway['status'] = 'confirmed';

    $this->postJson("/webhook/integrations/xpag/{$integration->webhook_token}", [
        'type' => 'cashin',
        'origin' => 'dynamic',
        'status' => 'confirmed',
        'amount' => 49.9,
        'currency' => 'BRL',
        'transaction_id' => 'pr_1',
        'external_id' => $payment->reference,
        'e2e' => 'E123',
    ])->assertOk();

    $payment->refresh();

    expect($payment->status)->toBe(FlowPaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID))->toHaveCount(1);

    // Read back by XPag's own id, not trusted from the body.
    expect(end($gateway['consulted']))->toBe(['transaction_id' => 'pr_1']);
});

test('a forged webhook cannot mark anything paid', function () {
    $gateway = [];
    xpgwFake($gateway);

    [$conversation, $integration] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    // The body says confirmed; XPag, asked, says it is still pending.
    $this->postJson("/webhook/integrations/xpag/{$integration->webhook_token}", [
        'type' => 'cashin',
        'status' => 'confirmed',
        'external_id' => $payment->reference,
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(FlowPaymentStatus::Pending)
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!');
});

test('cash-out notices and deliveries without our reference are acknowledged and ignored', function () {
    $gateway = [];
    xpgwFake($gateway);

    [$conversation, $integration] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();
    $gateway['status'] = 'confirmed';
    $gateway['consulted'] = [];

    $this->postJson("/webhook/integrations/xpag/{$integration->webhook_token}", [
        'type' => 'cashout', 'status' => 'completed', 'external_id' => $payment->reference,
    ])->assertOk();
    $this->postJson("/webhook/integrations/xpag/{$integration->webhook_token}", ['type' => 'cashin', 'status' => 'confirmed'])->assertOk();

    expect($gateway['consulted'])->toBe([])
        ->and($payment->fresh()->status)->toBe(FlowPaymentStatus::Pending);
});

test('an unpaid XPag Pix takes the failed branch once its 24 hours are up', function () {
    $gateway = ['status' => 'expired'];
    xpgwFake($gateway);

    [$conversation] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);

    // Past the node's own 60 minutes, still inside XPag's 24 hours: open.
    $gateway['status'] = 'pending';
    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();
    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Pending);

    $gateway['status'] = 'expired';
    $this->travel(23)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Expired)
        ->and(Fx::sentTexts($conversation))->toContain('Não recebemos o pagamento.')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_EXPIRED))->toHaveCount(1);
});

test('a charge whose create never came back is looked up by our reference', function () {
    $gateway = ['status' => 'confirmed'];
    xpgwFake($gateway);

    [$conversation] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();
    $payment->forceFill(['provider_payment_id' => null])->save();

    app(\App\Services\Flow\FlowPaymentService::class)->refresh($payment);

    expect(end($gateway['consulted']))->toMatchArray(['external_id' => $payment->reference])
        ->and($payment->fresh()->status)->toBe(FlowPaymentStatus::Paid);
});

test('a charge XPag does not know is still open, not an error', function () {
    $gateway = [];
    xpgwFake($gateway);

    [$conversation] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);
    $gateway['consult'] = 'not_found';

    $payment = app(\App\Services\Flow\FlowPaymentService::class)->refresh(FlowPayment::sole());

    expect($payment->status)->toBe(FlowPaymentStatus::Pending);
});

test('refusals take the failed branch at once, in our words', function (string $create, string $expected, string $notExpected) {
    $gateway = ['create' => $create];
    xpgwFake($gateway);

    [$conversation, $integration] = xpgwPaymentFixture();
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Failed)
        ->and($payment->failure_reason)->toContain($expected)
        ->and($payment->failure_reason)->not->toContain($notExpected)
        ->and($integration->fresh()->last_error)->toContain($expected)
        ->and(Fx::sentTexts($conversation))->toBe(['Não recebemos o pagamento.']);
})->with([
    'key without cash-in' => ['no_permission', 'cash-in', 'permissão para esta rota'],
    'account without KYC' => ['kyc', 'KYC', 'Conta precisa'],
    'ok=false on a 200' => ['soft_refusal', 'abaixo do mínimo aceito pela XPag', 'Valor abaixo'],
]);

test('a Pix in anything but reais is refused before XPag is called', function () {
    $gateway = [];
    xpgwFake($gateway);

    $integration = Fx::integration(Fx::tenant(), IntegrationProvider::XPag);

    $charge = new ChargeRequest(
        reference: 'pingly-fp-test',
        method: 'pix',
        amountCents: 4990,
        currency: 'MXN',
        description: 'Pedido',
        expiresAt: CarbonImmutable::now()->addHour(),
    );

    expect(fn () => IntegrationDrivers::payment($integration)->createCharge($charge))
        ->toThrow(fn (UpstreamServiceException $e) => expect($e->errorCode)->toBe('payment_currency_unsupported')
            ->and($e->getMessage())->toContain('reais'));

    Http::assertNothingSent();
});
