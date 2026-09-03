<?php

use App\Models\CreditTransaction;
use App\Models\AiModelPrice;
use App\Models\Setting;
use App\Services\Credits\CreditPricing;
use App\Services\Credits\CreditService;
use App\Services\AiTokens\AiTokenRentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreditFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('ai.credits.usd_brl_rate', 5.0);
    config()->set('ai.credits.markup_pct', 50);
    config()->set('ai.credits.fallback_run_cents', 5);
});

it('reads the commercial numbers from settings, not from config', function () {
    Setting::set(CreditPricing::KEY_MARKUP_PCT, '80');
    Setting::set(CreditPricing::KEY_USD_BRL_RATE, '6');

    // A price that needs a deploy is a price nobody adjusts — the whole reason
    // these live in the database.
    expect(CreditPricing::markupPct())->toBe(80.0)
        ->and(CreditPricing::usdBrlRate())->toBe(6.0)
        // US$0.01 × 6 × 1.8 = R$0.108 → 11 cents
        ->and(CreditPricing::priceRun(0.01)['cents'])->toBe(11);
});

it('falls back to config rather than to zero when a setting is cleared', function () {
    Setting::set(CreditPricing::KEY_USD_BRL_RATE, '');

    // A zero rate would price every run at the fallback and read exactly like
    // the hub having stopped reporting cost — a misconfiguration wearing the
    // disguise of a different problem.
    expect(CreditPricing::usdBrlRate())->toBe(5.0);
});

it('charges a model at its own markup when one is set', function () {
    AiModelPrice::create([
        'provider' => 'OPENAI',
        'model' => 'gpt-4o',
        'markup_pct' => 100,
    ]);

    // Platform markup is 50%, this model's is 100%: US$0.02 × 5 × 2 = R$0.20.
    expect(CreditPricing::priceRun(0.02, 'OPENAI', 'gpt-4o')['cents'])->toBe(20)
        // And a model with no row of its own still gets the platform number.
        ->and(CreditPricing::priceRun(0.02, 'OPENAI', 'gpt-4o-mini')['cents'])->toBe(15);
});

it('matches the model row whatever the capitalisation', function () {
    AiModelPrice::create(['provider' => 'openai', 'model' => 'GPT-4o', 'markup_pct' => 100]);

    // The provider arrives from the hub as OPENAI and is typed into the Back
    // Office as anything. A margin that silently stops applying because of
    // capitalisation is only ever found in a revenue report.
    expect(CreditPricing::priceRun(0.02, 'OPENAI', 'gpt-4o')['cents'])->toBe(20);
});

it('bills a real run at the model markup', function () {
    [$tenant, , $hubTenant] = CreditFixtures::workspace();
    CreditFixtures::poolKey();
    CreditFixtures::fakeHub();

    AiModelPrice::create(['provider' => 'OPENAI', 'model' => 'gpt-4o-mini', 'markup_pct' => 100]);

    $credential = app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');
    $agent = CreditFixtures::agent($hubTenant, $credential->id);

    $credits = app(CreditService::class);
    $credits->adjust($tenant, 10000, 'seed');
    $credits->chargeRun(creditRun($tenant, $agent, 0.02));

    // The per-model markup is the half of this feature that moves money; the
    // published price list is only ever shown.
    expect(CreditTransaction::where('tenant_id', $tenant->id)->latest('id')->first()->amount_cents)
        ->toBe(-20);
});

it('publishes a price list in the currency the balance is held in', function () {
    AiModelPrice::create([
        'provider' => 'OPENAI',
        'model' => 'gpt-4o-mini',
        'label' => 'Rápido',
        'input_usd_per_1m' => 0.15,
        'output_usd_per_1m' => 0.60,
    ]);

    $list = CreditPricing::priceList();

    // 0.15 × 5 × 1.5 = R$1.125 per 1M input tokens → 113 cents. Rounded to
    // nearest, not up: this is a published estimate, not a charge — the charge
    // is priceRun(), which always ceilings so a cheap run is never free.
    expect($list)->toHaveCount(1)
        ->and($list[0]['label'])->toBe('Rápido')
        ->and($list[0]['input_cents_per_1m'])->toBe(113)
        ->and($list[0]['output_cents_per_1m'])->toBe(450)
        // "Per million tokens" means nothing when choosing between two models,
        // so one worked reply is priced out of it.
        ->and($list[0]['example_reply_cents'])->toBeGreaterThan(0);
});

it('leaves a model off the price list rather than quoting it as free', function () {
    // Priced for its margin but with no list price recorded yet.
    AiModelPrice::create(['provider' => 'OPENAI', 'model' => 'gpt-4o', 'markup_pct' => 90]);
    AiModelPrice::create([
        'provider' => 'OPENAI', 'model' => 'gpt-4o-mini',
        'input_usd_per_1m' => 0.15, 'output_usd_per_1m' => 0.60,
        'is_listed' => false,
    ]);

    // A price list that quotes free is worse than one that is shorter, and a
    // model can be billed without being advertised.
    expect(CreditPricing::priceList())->toBeEmpty();
});

it('shows the workspace what each model costs without needing billing permission', function () {
    [$tenant, $user] = CreditFixtures::workspace();
    CreditFixtures::poolKey();
    CreditFixtures::fakeHub();

    AiModelPrice::create([
        'provider' => 'OPENAI', 'model' => 'gpt-4o-mini',
        'input_usd_per_1m' => 0.15, 'output_usd_per_1m' => 0.60,
    ]);

    app(AiTokenRentalService::class)->rent($tenant, 'OPENAI');

    // Carried on the rentals endpoint because choosing a model happens in the
    // agent form, and the person building an agent may not hold billing.view.
    $models = collect($this->actingAs($user)->getJson('/api/ai-hub/rentals')->assertOk()->json('models'));

    $priced = $models->firstWhere('model', 'gpt-4o-mini');

    expect($priced['input_cents_per_1m'])->toBe(113)
        ->and($priced['priced'])->toBeTrue()
        // Every other model the provider runs is offered too — and quoted.
        // `priced` false still means "no admin has typed a row for this one",
        // which is what the Back Office list and the per-model margin key off;
        // it never meant we could not say what it costs. The catalogue carries
        // the provider's USD figures, so the estimate comes from those at the
        // platform rate and markup, and a picker no longer quotes half its
        // options and leaves the rest blank.
        ->and($models->firstWhere('model', 'gpt-5')['priced'])->toBeFalse()
        ->and($models->firstWhere('model', 'gpt-5')['example_reply_cents'])->toBeGreaterThan(0)
        ->and($models->firstWhere('model', 'gpt-5')['input_cents_per_1m'])->toBeGreaterThan(0)
        // Only providers the platform can actually rent.
        ->and($models->pluck('provider')->unique()->all())->toBe(['OPENAI']);
});

it('resolves a dated model snapshot to its base price, not a costlier neighbour', function () {
    // Providers ship `gpt-5-mini-2025-08-07` and friends. Longest-prefix, so
    // this must not land on `gpt-5` — eight times the input price.
    expect(\App\Services\AiTokens\KnownModelPrices::for('OPENAI', 'gpt-5-mini-2025-08-07'))
        ->toBe(['input' => 0.25, 'output' => 2.00])
        ->and(\App\Services\AiTokens\KnownModelPrices::for('ANTHROPIC', 'claude-sonnet-4-5-20250929'))
        ->toBe(['input' => 3.00, 'output' => 15.00]);
});

it('says it does not know a model rather than guessing a price for it', function () {
    // The catalogue always trails the providers. Null is what makes the Back
    // Office leave the price fields empty instead of seeding a wrong number.
    expect(\App\Services\AiTokens\KnownModelPrices::for('OPENAI', 'gpt-9-something'))->toBeNull();
});
