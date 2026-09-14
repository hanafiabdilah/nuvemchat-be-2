<?php

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Models\Conversation;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\Integration;
use App\Services\Flow\FlowExecutor;
use App\Services\Flow\PaymentNodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

const ASGW_SANDBOX_KEY = '$aact_hmlg_fixture0000';

function asgwPath(Request $request): string
{
    return (string) parse_url($request->url(), PHP_URL_PATH);
}

/**
 * Asaas, Stripe and the WhatsApp channel behind one fake, driven by a mutable
 * array so a test can say "now the gateway says it was paid" and mean it.
 *
 * @param  array<string, mixed>  $gateway
 */
function asgwFakeGateways(array &$gateway): void
{
    Http::fake(function (Request $request) use (&$gateway) {
        $url = $request->url();
        $path = asgwPath($request);
        $method = $request->method();

        if (str_contains($url, 'graph.facebook.com')) {
            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]);
        }

        if (str_contains($url, 'asaas.com')) {
            return match (true) {
                $path === '/v3/finance/balance' => Http::response(['balance' => 0]),
                $path === '/v3/myAccount/commercialInfo' => Http::response([
                    'companyName' => 'Loja Teste', 'email' => 'loja@example.com', 'status' => 'APPROVED',
                ]),
                $path === '/v3/webhooks' && $method === 'GET' => Http::response(['data' => []]),
                $path === '/v3/webhooks' && $method === 'POST' => Http::response(['id' => 'wh_asaas_1']),
                $path === '/v3/customers' && $method === 'GET' => Http::response([
                    'data' => ($gateway['asaas_customer_exists'] ?? false) ? [['id' => 'cus_existing']] : [],
                ]),
                $path === '/v3/customers' && $method === 'POST' => Http::response(['id' => 'cus_1']),
                $path === '/v3/payments' && $method === 'GET' => Http::response(['data' => []]),
                $path === '/v3/payments' && $method === 'POST' => (function () use (&$gateway) {
                    $gateway['asaas_creates'] = ($gateway['asaas_creates'] ?? 0) + 1;

                    return Http::response([
                        'id' => 'pay_1',
                        'status' => 'PENDING',
                        'invoiceUrl' => 'https://www.asaas.com/i/pay_1',
                    ]);
                })(),
                $path === '/v3/payments/pay_1/pixQrCode' => Http::response([
                    'payload' => Fx::PIX_CODE,
                    'encodedImage' => 'iVBORw0KGgo=',
                ]),
                $path === '/v3/payments/pay_1' && $method === 'GET' => Http::response([
                    'id' => 'pay_1',
                    'status' => $gateway['asaas_status'] ?? 'PENDING',
                    'paymentDate' => '2026-09-14',
                    'externalReference' => $gateway['asaas_reference'] ?? null,
                ]),
                $path === '/v3/payments/pay_1' && $method === 'DELETE' => Http::response(['deleted' => true, 'id' => 'pay_1']),
                default => Http::response(['errors' => [['code' => 'not_found', 'description' => 'not found']]], 404),
            };
        }

        if (str_contains($url, 'api.stripe.com')) {
            return match (true) {
                $path === '/v1/balance' => Http::response(['object' => 'balance']),
                $path === '/v1/account' => Http::response([
                    'id' => 'acct_1', 'email' => 'loja@example.com', 'country' => 'BR',
                    'settings' => ['dashboard' => ['display_name' => 'Loja Teste']],
                ]),
                $path === '/v1/webhook_endpoints' && $method === 'GET' => Http::response(['data' => []]),
                $path === '/v1/webhook_endpoints' && $method === 'POST' => Http::response(['id' => 'we_1']),
                $path === '/v1/checkout/sessions/cs_test_1/expire' => (function () use (&$gateway) {
                    $gateway['stripe_expired'] = true;

                    return Http::response(['id' => 'cs_test_1', 'status' => 'expired']);
                })(),
                $path === '/v1/checkout/sessions' && $method === 'POST' => (function () use (&$gateway, $request) {
                    $gateway['stripe_creates'] = ($gateway['stripe_creates'] ?? 0) + 1;
                    $gateway['stripe_body'] = $request->data();
                    $gateway['stripe_idempotency_key'] = $request->header('Idempotency-Key')[0] ?? null;

                    return Http::response([
                        'id' => 'cs_test_1',
                        'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
                        'status' => 'open',
                        'payment_status' => 'unpaid',
                        'expires_at' => (int) $request['expires_at'],
                    ]);
                })(),
                $path === '/v1/checkout/sessions/cs_test_1' && $method === 'GET' => Http::response([
                    'id' => 'cs_test_1',
                    'status' => $gateway['stripe_status'] ?? 'open',
                    'payment_status' => $gateway['stripe_payment_status'] ?? 'unpaid',
                    'payment_intent' => ['id' => 'pi_1', 'status' => 'succeeded'],
                ]),
                default => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'No such resource']], 404),
            };
        }

        return Http::response([], 404);
    });
}

/**
 * start → payment → (paid) "Pagamento confirmado!" · (failed) "Não recebemos o pagamento."
 *
 * @param  array<string, mixed>  $data
 * @return array{0: Conversation, 1: Integration, 2: \App\Models\FlowNode}
 */
function asgwPaymentFixture(IntegrationProvider $provider, array $data = []): array
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

// ───────────────────────────────  Asaas  ───────────────────────────────

test('connecting Asaas verifies the key and registers the webhook with our token', function () {
    $gateway = [];
    asgwFakeGateways($gateway);
    $user = Fx::user();

    $this->actingAs($user, 'sanctum')->postJson('/api/integrations', [
        'provider' => 'asaas',
        'name' => 'Asaas',
        'credentials' => Fx::CREDENTIALS['asaas'],
    ])->assertCreated()
        ->assertJsonPath('data.category', 'payment')
        ->assertJsonPath('data.account.account_name', 'Loja Teste')
        ->assertJsonPath('data.account.environment', 'production')
        ->assertJsonPath('data.webhook.registered', true);

    $integration = Integration::sole();

    Http::assertSent(fn (Request $request) => asgwPath($request) === '/v3/finance/balance'
        && str_starts_with($request->url(), 'https://api.asaas.com/')
        && ($request->header('access_token')[0] ?? null) === Fx::CREDENTIALS['asaas']['api_key']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && asgwPath($request) === '/v3/webhooks'
        && $request['authToken'] === $integration->webhook_token
        && $request['url'] === $integration->webhookUrl()
        && in_array('PAYMENT_RECEIVED', $request['events'], true));

    expect($integration->meta['webhook']['webhook_ids'])->toBe(['wh_asaas_1']);
});

test('an Asaas sandbox key talks to the sandbox', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'asaas',
        'name' => 'Asaas teste',
        'credentials' => ['api_key' => ASGW_SANDBOX_KEY],
    ])->assertCreated()->assertJsonPath('data.account.environment', 'sandbox');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api-sandbox.asaas.com/v3/finance/balance'));
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.asaas.com/'));
});

test('an Asaas charge without the payer CPF/CNPJ takes the failed branch without calling Asaas', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    // A variable nobody filled in resolves to an empty string.
    [$conversation] = asgwPaymentFixture(IntegrationProvider::Asaas, ['payer_document' => '{{cpf}}']);
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Failed)
        ->and($payment->failure_reason)->toContain('CPF/CNPJ')
        ->and(Fx::sentTexts($conversation))->toBe(['Não recebemos o pagamento.'])
        ->and(Fx::notes($conversation, PaymentNodes::INFO_FAILED))->toHaveCount(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'asaas.com'));
});

test('an invalid CPF/CNPJ is refused the same way', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation] = asgwPaymentFixture(IntegrationProvider::Asaas, ['payer_document' => '123']);
    (new FlowExecutor)->startFlow($conversation);

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Failed)
        ->and(FlowPayment::sole()->failure_reason)->toContain('"123"');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'asaas.com'));
});

test('an Asaas Pix creates the customer, files the charge under our reference and sends the code', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation, $integration, $node] = asgwPaymentFixture(IntegrationProvider::Asaas);
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Pending)
        ->and($payment->provider_payment_id)->toBe('pay_1')
        ->and($payment->pix_code)->toBe(Fx::PIX_CODE)
        ->and($payment->payment_url)->toBe('https://www.asaas.com/i/pay_1')
        ->and(Fx::sentTexts($conversation))->toBe(['Segue o Pix de R$ 49,90:', Fx::PIX_CODE]);

    // Looked up by our reference before anything is created.
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && asgwPath($request) === '/v3/payments'
        && str_contains($request->url(), 'externalReference='.$payment->reference));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && asgwPath($request) === '/v3/customers'
        && $request['cpfCnpj'] === '12345678909'
        && $request['name'] === 'Maria Souza'
        && $request['notificationDisabled'] === true);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && asgwPath($request) === '/v3/payments'
        && $request['customer'] === 'cus_1'
        && $request['billingType'] === 'PIX'
        && (float) $request['value'] === 49.9
        && $request['externalReference'] === $payment->reference);

    Http::assertSent(fn (Request $request) => asgwPath($request) === '/v3/payments/pay_1/pixQrCode');

    $state = FlowState::where('conversation_id', $conversation->id)->sole();

    expect($state->state_data[PaymentNodes::stateKey($node->id)])->toBe($payment->id)
        ->and($gateway['asaas_creates'])->toBe(1);
});

test('an existing Asaas customer is reused, never created twice', function () {
    $gateway = ['asaas_customer_exists' => true];
    asgwFakeGateways($gateway);

    [$conversation] = asgwPaymentFixture(IntegrationProvider::Asaas);
    (new FlowExecutor)->startFlow($conversation);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && asgwPath($request) === '/v3/customers');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && asgwPath($request) === '/v3/payments'
        && $request['customer'] === 'cus_existing');
});

test('an Asaas webhook carrying our token and a paid read-back takes the paid branch', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation, $integration] = asgwPaymentFixture(IntegrationProvider::Asaas);
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $gateway['asaas_status'] = 'RECEIVED';

    $this->withHeaders(['asaas-access-token' => $integration->webhook_token])
        ->postJson("/webhook/integrations/asaas/{$integration->webhook_token}", [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['id' => 'pay_1', 'externalReference' => $payment->reference, 'status' => 'RECEIVED'],
        ])->assertOk();

    $payment->refresh();

    expect($payment->status)->toBe(FlowPaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!')
        ->and(Fx::sentTexts($conversation))->not->toContain('Não recebemos o pagamento.')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID))->toHaveCount(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && asgwPath($request) === '/v3/payments/pay_1');
});

test('an Asaas webhook with the wrong token does nothing', function () {
    // The gateway would say it was paid — but a delivery that is not from our
    // registration is not even asked about.
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation, $integration] = asgwPaymentFixture(IntegrationProvider::Asaas);
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $gateway['asaas_status'] = 'RECEIVED';

    $this->withHeaders(['asaas-access-token' => 'not-our-token'])
        ->postJson("/webhook/integrations/asaas/{$integration->webhook_token}", [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['id' => 'pay_1', 'externalReference' => $payment->reference],
        ])->assertOk();

    // No header at all is the same.
    $this->postJson("/webhook/integrations/asaas/{$integration->webhook_token}", [
        'payment' => ['id' => 'pay_1', 'externalReference' => $payment->reference],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(FlowPaymentStatus::Pending)
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && asgwPath($request) === '/v3/payments/pay_1');
});

test('an unpaid Asaas charge expires, takes the failed branch and is deleted at Asaas', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation] = asgwPaymentFixture(IntegrationProvider::Asaas, ['expires_in_minutes' => 30]);
    (new FlowExecutor)->startFlow($conversation);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Expired)
        ->and(Fx::sentTexts($conversation))->toContain('Não recebemos o pagamento.')
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_EXPIRED))->toHaveCount(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && asgwPath($request) === '/v3/payments/pay_1');
});

test('an Asaas charge paid in the last second is paid, and not deleted', function () {
    $gateway = ['asaas_status' => 'RECEIVED'];
    asgwFakeGateways($gateway);

    [$conversation] = asgwPaymentFixture(IntegrationProvider::Asaas, ['expires_in_minutes' => 30]);
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Paid)
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

// ───────────────────────────────  Stripe  ───────────────────────────────

test('connecting Stripe verifies the key and registers a webhook endpoint', function () {
    $gateway = [];
    asgwFakeGateways($gateway);
    $user = Fx::user();

    $this->actingAs($user, 'sanctum')->postJson('/api/integrations', [
        'provider' => 'stripe',
        'name' => 'Stripe',
        'credentials' => Fx::CREDENTIALS['stripe'],
    ])->assertCreated()
        ->assertJsonPath('data.payment_methods', ['checkout'])
        ->assertJsonPath('data.account.account_name', 'Loja Teste')
        ->assertJsonPath('data.account.environment', 'production')
        ->assertJsonPath('data.webhook.registered', true);

    $integration = Integration::sole();

    Http::assertSent(fn (Request $request) => asgwPath($request) === '/v1/balance'
        && ($request->header('Authorization')[0] ?? null) === 'Bearer '.Fx::CREDENTIALS['stripe']['secret_key']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && asgwPath($request) === '/v1/webhook_endpoints'
        && $request['url'] === $integration->webhookUrl()
        && in_array('checkout.session.completed', (array) $request['enabled_events'], true));

    expect($integration->meta['webhook']['webhook_ids'])->toBe(['we_1']);
});

test('a Stripe checkout sends the link, is idempotent on our reference and lives at least 30 minutes', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation] = asgwPaymentFixture(IntegrationProvider::Stripe, [
        'method' => 'checkout',
        'expires_in_minutes' => 10,
        'message' => 'Finalize seu pagamento:',
        'payer_document' => null,
    ]);

    $startedAt = now()->getTimestamp();
    (new FlowExecutor)->startFlow($conversation);

    $payment = FlowPayment::sole();

    expect($payment->status)->toBe(FlowPaymentStatus::Pending)
        ->and($payment->provider_payment_id)->toBe('cs_test_1')
        ->and($payment->payment_url)->toBe('https://checkout.stripe.com/c/pay/cs_test_1')
        ->and(Fx::sentTexts($conversation))->toBe(["Finalize seu pagamento:\n\nhttps://checkout.stripe.com/c/pay/cs_test_1"])
        ->and($gateway['stripe_idempotency_key'])->toBe($payment->reference)
        ->and($gateway['stripe_body']['client_reference_id'])->toBe($payment->reference)
        ->and((int) $gateway['stripe_body']['line_items'][0]['price_data']['unit_amount'])->toBe(4990)
        ->and($gateway['stripe_body']['line_items'][0]['price_data']['currency'])->toBe('brl')
        ->and((int) $gateway['stripe_body']['expires_at'])->toBeGreaterThanOrEqual($startedAt + 30 * 60)
        ->and((int) $gateway['stripe_body']['expires_at'])->toBeLessThanOrEqual($startedAt + 24 * 60 * 60)
        ->and($gateway['stripe_body']['success_url'])->toContain("/flow-payments/{$payment->reference}/done")
        // The deadline the flow waits on is the one Stripe will honour.
        ->and($payment->expires_at->getTimestamp())->toBe((int) $gateway['stripe_body']['expires_at']);
});

test('a Stripe webhook naming our reference and a paid read-back takes the paid branch', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation, $integration] = asgwPaymentFixture(IntegrationProvider::Stripe, ['method' => 'checkout']);
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    $gateway['stripe_status'] = 'complete';
    $gateway['stripe_payment_status'] = 'paid';

    $this->postJson("/webhook/integrations/stripe/{$integration->webhook_token}", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['id' => 'cs_test_1', 'client_reference_id' => $payment->reference]],
    ])->assertOk();

    $payment->refresh();

    expect($payment->status)->toBe(FlowPaymentStatus::Paid)
        ->and(Fx::sentTexts($conversation))->toContain('Pagamento confirmado!')
        ->and(Fx::notes($conversation, PaymentNodes::INFO_PAID))->toHaveCount(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && asgwPath($request) === '/v1/checkout/sessions/cs_test_1'
        && str_contains(urldecode($request->url()), 'payment_intent'));
});

test('a forged Stripe webhook cannot mark a checkout paid', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation, $integration] = asgwPaymentFixture(IntegrationProvider::Stripe, ['method' => 'checkout']);
    (new FlowExecutor)->startFlow($conversation);
    $payment = FlowPayment::sole();

    // The body says paid; Stripe, asked with the workspace's key, says open.
    $this->postJson("/webhook/integrations/stripe/{$integration->webhook_token}", [
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['client_reference_id' => $payment->reference, 'payment_status' => 'paid']],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(FlowPaymentStatus::Pending)
        ->and(Fx::sentTexts($conversation))->not->toContain('Pagamento confirmado!');
});

test('an unpaid Stripe checkout expires and the session is expired at Stripe', function () {
    $gateway = [];
    asgwFakeGateways($gateway);

    [$conversation] = asgwPaymentFixture(IntegrationProvider::Stripe, ['method' => 'checkout']);
    (new FlowExecutor)->startFlow($conversation);

    $this->travel(2)->hours();
    $this->artisan('flow-payments:sync')->assertSuccessful();

    expect(FlowPayment::sole()->status)->toBe(FlowPaymentStatus::Expired)
        ->and(Fx::sentTexts($conversation))->toContain('Não recebemos o pagamento.')
        ->and($gateway['stripe_expired'] ?? false)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && asgwPath($request) === '/v1/checkout/sessions/cs_test_1/expire');
});

test('the page after a Stripe payment is a plain html page that claims nothing', function () {
    $this->get('/flow-payments/pingly-fp-anything/done')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('Pagamento enviado');
});
