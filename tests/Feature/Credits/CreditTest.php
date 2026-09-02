<?php

use App\Enums\Credit\CreditTransactionType;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Exceptions\Billing\CreditExhaustedException;
use App\Models\CreditTransaction;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubRun;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Credits\CreditPricing;
use App\Services\Credits\CreditService;
use App\Services\AiTokens\AiTokenRentalService;
use App\Services\Billing\BillingService;
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
    config()->set('services.mercadopago.enforce', true);

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
    config()->set('services.mercadopago.enforce', true);
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
        'mp_payment_id' => 'mp-1',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $billing = app(BillingService::class);

    $billing->applyPaymentUpdate(['id' => 'mp-1', 'status' => 'approved']);
    // MercadoPago delivers the same notification more than once; a credit
    // applied twice is money given away.
    $billing->applyPaymentUpdate(['id' => 'mp-1', 'status' => 'approved']);

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
        'mp_payment_id' => 'mp-2',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $billing = app(BillingService::class);
    $billing->applyPaymentUpdate(['id' => 'mp-2', 'status' => 'approved']);
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
