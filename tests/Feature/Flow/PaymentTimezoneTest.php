<?php

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Models\FlowPayment;
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
 * Mercado Pago answers dates in its own zone (-04:00). Stored without
 * conversion, "11:24 UTC" became 07:24 UTC — a Pix that had expired hours
 * before it was created, closed by the expiry job seconds later (Sep 2026).
 */
it('keeps a Mercado Pago Pix valid for the minutes asked, whatever zone the gateway answers in', function () {
    $this->freezeTime();

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'graph.facebook.com')) {
            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]);
        }

        if (str_contains($request->url(), 'api.mercadopago.com/v1/payments')) {
            return Http::response([
                'id' => 1234567,
                'status' => 'pending',
                'date_of_expiration' => now()->addHour()->setTimezone('-04:00')->format('Y-m-d\TH:i:s.vP'),
                'point_of_interaction' => ['transaction_data' => ['qr_code' => Fx::PIX_CODE]],
            ], 201);
        }

        return Http::response([], 404);
    });

    $tenant = Fx::tenant();
    $integration = Fx::integration($tenant, IntegrationProvider::MercadoPago);
    $flow = Fx::flow($tenant);
    $payment = Fx::node($flow, NodeType::Payment, [
        'integration_id' => $integration->id, 'method' => 'pix', 'amount' => '10,00', 'expires_in_minutes' => 60,
    ]);
    Fx::edge(Fx::start($flow), $payment);
    Fx::edge($payment, Fx::say($flow, 'Pago'), PaymentNodes::BRANCH_PAID);

    (new FlowExecutor)->startFlow(Fx::conversation($tenant, $flow));

    $row = FlowPayment::firstOrFail();

    expect(abs($row->expires_at->diffInSeconds(now()->addHour())))->toBeLessThan(2)
        ->and($row->status)->toBe(FlowPaymentStatus::Pending);

    // The expiry job, run now, must leave it alone: the hour has not passed.
    (new FlowPaymentService)->expire($row->fresh());

    expect($row->fresh()->status)->toBe(FlowPaymentStatus::Pending);
});

it('stores a paid time reported in the gateway zone as the same moment', function () {
    $this->freezeTime();

    $tenant = Fx::tenant();
    $row = FlowPayment::create([
        'tenant_id' => $tenant->id, 'reference' => 'pingly-fp-test', 'method' => 'pix',
        'amount_cents' => 1000, 'currency' => 'BRL', 'status' => FlowPaymentStatus::Pending,
        'expires_at' => now()->addHour(),
    ]);

    $paidAt = now()->setTimezone('America/Sao_Paulo');

    (new FlowPaymentService)->settle($row, FlowPaymentStatus::Paid, $paidAt);

    expect(abs($row->fresh()->paid_at->diffInSeconds(now())))->toBeLessThan(2);
});
