<?php

use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Credit\CreditTransactionType;
use App\Exceptions\Billing\CreditExhaustedException;
use App\Exceptions\UserFacingException;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubRun;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Market;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiTokens\AiTokenRentalService;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceConfig;
use App\Services\Credits\CreditPricing;
use App\Services\Credits\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    // Fixed so these tests state a price rather than restating the formula:
    // US$1 → R$5, plus 50% → R$7.50.
    config()->set('ai.credits.usd_brl_rate', 5.0);
    config()->set('ai.credits.markup_pct', 50);
    config()->set('ai.credits.fallback_run_cents', 5);
});

/** A run row, in the shape `persistRun` would have written it. */
function creditRun(Tenant $tenant, AiHubAgent $agent, ?float $costUsd): AiHubRun
{
    return AiHubRun::create([
        'tenant_id' => $tenant->id,
        'ai_hub_agent_id' => $agent->id,
        'conversation_id' => CreditFixtures::conversation($tenant)->id,
        'hub_run_id' => 'run-'.uniqid(),
        'status' => 'COMPLETED',
        'provider' => 'OPENAI',
        'model' => 'gpt-4o-mini',
        'input_message' => 'oi',
        'output_message' => 'olá',
        'total_tokens' => 120,
        'cost_usd' => $costUsd,
    ]);
}

/** Reaches the pre-run gate, which is protected because nothing else may call it. */
function creditGate(): object
{
    return new class extends AiAgentHubTenantService
    {
        public function check(AiHubAgent $agent): void
        {
            $this->assertCanSpendCredit($agent);
        }
    };
}

/**
 * A billable workspace in a given country.
 *
 * Named for this file: Pest loads every test file into one process, so a helper
 * sharing a name with a sibling's is a fatal redeclare. CreditFixtures' own
 * workspace is always Brazilian and carries no document, and both matter here.
 */
function topupWorkspace(string $marketCode = 'BR'): Tenant
{
    if ($marketCode !== 'BR') {
        Market::create([
            'code' => $marketCode,
            'name' => 'Indonesia',
            'currency' => 'IDR',
            'default_locale' => 'id',
            'default_timezone' => 'Asia/Jakarta',
            'phone_country' => '62',
            'status' => 'active',
        ]);
    }

    $user = User::factory()->create(['email' => 'topup-'.uniqid().'@example.test']);

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    // Past assertBillable, so what these tests fail on is the payment method
    // and nothing else.
    $tenant->forceFill([
        'billing_name' => 'Acme',
        'billing_document_type' => $marketCode === 'BR' ? 'CNPJ' : 'NPWP',
        'billing_document_number' => $marketCode === 'BR' ? '12345678000199' : '091234567890123',
    ])->save();

    return $tenant->fresh();
}

it('refuses a top-up in a country whose rails cannot take a Pix', function () {
    // Without a key the client refuses before any HTTP call, the guard's
    // fail-open path swallows that, and this test would pass on the wrong
    // exception entirely.
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');

    // This endpoint issues a Pix and nothing else, and Pix is Brazilian. Before
    // the guard, an Indonesian workspace got a real invoice, a gateway refusal
    // and generic copy telling them to try another payment method — one this
    // endpoint never offered a choice about.
    Http::fake([
        '*/payment-methods*' => Http::response(['data' => [
            ['method' => 'card', 'instruction_type' => 'card', 'merchant_initiated_cards' => false],
        ]]),
    ]);

    $tenant = topupWorkspace('ID');

    expect(fn () => app(BillingService::class)->createCreditTopupPixInvoice($tenant, 500000))
        ->toThrow(UserFacingException::class);

    // Refused before anything was written or charged: an invoice left behind
    // would sit in the customer's list as a bill they can never pay.
    expect(Invoice::count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/payments'));
});

it('still issues a top-up where Pix exists', function () {
    // The control that matters: a guard that refuses everybody would pass the
    // test above and quietly stop every Brazilian customer from paying us.
    Setting::set(PaymentServiceConfig::KEY_API_KEY, 'ps_test_key');

    Http::fake([
        '*/payment-methods*' => Http::response(['data' => [
            ['method' => 'pix', 'instruction_type' => 'pix', 'merchant_initiated_cards' => false],
        ]]),
        '*/payments' => Http::response(['data' => [
            'id' => 'pay_1',
            'status' => 'pending',
            'order_reference' => 'ref',
            'instructions' => ['type' => 'pix', 'qr_code' => 'QR'],
        ]]),
    ]);

    $invoice = app(BillingService::class)->createCreditTopupPixInvoice(topupWorkspace(), 5000);

    expect($invoice->purpose)->toBe(InvoicePurpose::CreditTopup)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->pix_qr_code)->toBe('QR');
});

it('prices a run at the provider cost plus the markup, converted', function () {
    // US$0.01 × 5 × 1.5 = R$0.075 → 8 cents, rounded up so a cheap run is
    // never free and an empty wallet cannot keep talking.
    expect(CreditPricing::priceRun(0.01)['cents'])->toBe(8);
});

it('charges the fallback rather than nothing when the hub reports no cost', function () {
    $price = CreditPricing::priceRun(null);

    // `cost_usd` is already null for a share of rows — the Back Office AI Usage
    // page reports `costed_runs` separately for exactly this reason. Treating
    // those as free would turn the rental into a giveaway with nothing in the
    // product to show it happened.
    expect($price['cents'])->toBe(5)
        ->and($price['estimated'])->toBeTrue();
});

it('debits the wallet for a run on a rented key', function () {
    [$tenant, , $hubTenant] = CreditFixtures::workspace();
    CreditFixtures::poolKey();
    CreditFixtures::fakeHub();

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');
    $agent = CreditFixtures::agent($hubTenant, $credential->id);

    $credits = app(CreditService::class);
    $credits->adjust($tenant, 10000, 'seed');

    $transaction = $credits->chargeRun(creditRun($tenant, $agent, 0.02));

    expect($transaction->type)->toBe(CreditTransactionType::Usage)
        // US$0.02 × 5 × 1.5 = R$0.15
        ->and($transaction->amount_cents)->toBe(-15)
        ->and($transaction->balance_after_cents)->toBe(9985)
        ->and($credits->balanceCents($tenant->fresh()))->toBe(9985);
});

it('never charges the same run twice', function () {
    [$tenant, , $hubTenant] = CreditFixtures::workspace();
    $agent = CreditFixtures::agent($hubTenant);

    $credits = app(CreditService::class);
    $credits->adjust($tenant, 10000, 'seed');

    $run = creditRun($tenant, $agent, 0.02);

    $credits->chargeRun($run);
    // A retried job that already got as far as persisting the run must not bill
    // for it again — enforced by the ledger's unique index rather than by a
    // check-then-write two workers could both pass.
    $second = $credits->chargeRun($run);

    expect($second)->toBeNull()
        ->and(CreditTransaction::where('ai_hub_run_id', $run->id)->count())->toBe(1)
        ->and($credits->balanceCents($tenant->fresh()))->toBe(9985);
});

it('lets a run through on the workspace own key with an empty wallet', function () {
    [, , $hubTenant] = CreditFixtures::workspace();
    config()->set('services.billing.enforce', true);

    // No pool key behind this credential: the workspace is spending its own
    // money at the provider and owes the platform nothing per run. Gating it
    // would be inventing a limit nobody sold.
    $credential = AiHubProviderCredential::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_provider_credential_id' => 'hub-cred-own',
        'provider' => 'OPENAI',
        'name' => 'Minha chave',
        'status' => 'ACTIVE',
    ]);

    $agent = CreditFixtures::agent($hubTenant, $credential->id);

    expect(fn () => creditGate()->check($agent))->not->toThrow(CreditExhaustedException::class);
});

it('refuses a run on a rented key once the balance is spent', function () {
    [$tenant, , $hubTenant] = CreditFixtures::workspace();
    config()->set('services.billing.enforce', true);
    CreditFixtures::poolKey();
    CreditFixtures::fakeHub();

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');
    $agent = CreditFixtures::agent($hubTenant, $credential->id);

    expect(fn () => creditGate()->check($agent))->toThrow(CreditExhaustedException::class);
});

it('credits the balance once when a top-up is paid, however often the webhook fires', function () {
    [$tenant] = CreditFixtures::workspace();

    $invoice = Invoice::create([
        'tenant_id' => $tenant->id,
        'purpose' => InvoicePurpose::CreditTopup,
        'status' => InvoiceStatus::Pending,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 5000,
        'currency' => 'BRL',
        'payment_id' => 'mp-1',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $billing = app(BillingService::class);

    $billing->applyPaymentUpdate(['id' => 'mp-1', 'status' => 'paid']);
    // MercadoPago delivers the same notification more than once; a credit
    // applied twice is money given away.
    $billing->applyPaymentUpdate(['id' => 'mp-1', 'status' => 'paid']);

    expect(app(CreditService::class)->balanceCents($tenant->fresh()))->toBe(5000)
        ->and(CreditTransaction::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('takes the credit back when a top-up is refunded', function () {
    [$tenant] = CreditFixtures::workspace();

    Invoice::create([
        'tenant_id' => $tenant->id,
        'purpose' => InvoicePurpose::CreditTopup,
        'status' => InvoiceStatus::Pending,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 5000,
        'currency' => 'BRL',
        'payment_id' => 'mp-2',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $billing = app(BillingService::class);
    $billing->applyPaymentUpdate(['id' => 'mp-2', 'status' => 'paid']);
    $billing->applyPaymentUpdate(['id' => 'mp-2', 'status' => 'refunded']);

    // Its own negative row, not a deleted credit: the money did arrive and then
    // leave, and the statement has to reconcile against MercadoPago's.
    expect(app(CreditService::class)->balanceCents($tenant->fresh()))->toBe(0)
        ->and(CreditTransaction::where('tenant_id', $tenant->id)
            ->where('type', CreditTransactionType::Refund->value)->count())->toBe(1);
});

it('exposes the balance and statement to the workspace, without the wholesale cost', function () {
    [$tenant, $user] = CreditFixtures::workspace();

    app(CreditService::class)->adjust($tenant, 2500, 'cortesia');

    $response = $this->actingAs($user)->getJson('/api/credits');

    $response->assertOk()
        ->assertJsonPath('data.balance_cents', 2500)
        ->assertJsonPath('transactions.0.amount_cents', 2500);

    // The provider's own price is the margin. Printing it beside what the
    // customer paid turns every statement into an argument about it.
    expect($response->json('transactions.0'))->not->toHaveKey('cost_usd');
});

it('refuses a top-up below the floor instead of issuing a Pix that loses money', function () {
    [, $user] = CreditFixtures::workspace();
    config()->set('ai.credits.min_topup_cents', 1000);

    $this->actingAs($user)
        ->postJson('/api/credits/topup', ['amount_cents' => 100])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount_cents');
});
