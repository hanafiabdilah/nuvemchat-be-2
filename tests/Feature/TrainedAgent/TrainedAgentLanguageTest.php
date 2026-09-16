<?php

use App\Models\Market;
use App\Models\Tenant;
use App\Models\TrainedAgentBlueprint;
use App\Models\User;
use App\Services\TrainedAgent\TrainedAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Which trained agents a country is offered.
 *
 * The catalog has been per market since prices moved there — a blueprint with
 * no price in a market is not sold there. It was never per *language*, and the
 * two are not the same question: an Indonesian workspace was shown agents whose
 * prompt, knowledge and training examples are Portuguese. Nothing would have
 * failed. The sale completes, the fork succeeds, and the bot answers that
 * business's customers in a language it does not speak.
 *
 * These tests exist because that outcome is invisible from every side except
 * the customer's.
 */
uses(RefreshDatabase::class);

// Named for this file on purpose: Pest loads every test file into one process,
// so a second helper of the same name anywhere is a fatal redeclare.
function agentLangIndonesia(): Market
{
    return Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

function agentLangTenant(string $marketCode): Tenant
{
    $user = User::factory()->create();

    $tenant = new Tenant(['user_id' => $user->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->refresh();
}

/**
 * A published blueprint written in `$locale` (null = not tied to one), priced
 * in every market named — being priced is what puts it on sale there.
 *
 * @param  array<string, int>  $prices  market code => amount in minor units
 */
function agentLangBlueprint(string $name, ?string $locale, array $prices): TrainedAgentBlueprint
{
    $blueprint = TrainedAgentBlueprint::create([
        'name' => $name,
        'slug' => str($name)->slug()->value(),
        'locale' => $locale,
        'model' => 'gpt-4o-mini',
        'system_prompt' => 'You are helpful.',
        'price_cents' => 4990,
        'currency' => 'BRL',
        'is_active' => true,
        'is_public' => true,
    ]);

    $blueprint->syncMarketPrices($prices);

    return $blueprint->refresh();
}

/** @return array<int, string> the names a workspace is offered, in catalog order */
function agentLangCatalogFor(Tenant $tenant): array
{
    return app(TrainedAgentService::class)->catalog($tenant)->pluck('name')->all();
}

it('offers a workspace only agents written in a language its country reads', function () {
    agentLangIndonesia();

    agentLangBlueprint('Atendimento', 'pt_BR', ['BR' => 4990, 'ID' => 14900000]);
    agentLangBlueprint('Layanan', 'id', ['BR' => 4990, 'ID' => 14900000]);

    expect(agentLangCatalogFor(agentLangTenant('BR')))->toBe(['Atendimento'])
        ->and(agentLangCatalogFor(agentLangTenant('ID')))->toBe(['Layanan']);
});

it('offers an agent tied to no language to everybody', function () {
    agentLangIndonesia();

    agentLangBlueprint('Universal', null, ['BR' => 4990, 'ID' => 14900000]);

    expect(agentLangCatalogFor(agentLangTenant('BR')))->toBe(['Universal'])
        ->and(agentLangCatalogFor(agentLangTenant('ID')))->toBe(['Universal']);
});

it('does not let a language-neutral agent escape the country it is priced in', function () {
    agentLangIndonesia();

    // Priced in Brazil only, tied to no language.
    agentLangBlueprint('So Brasil', null, ['BR' => 4990]);

    // ⚠️ The reason scopeWrittenIn wraps its orWhere in a closure. Without the
    // grouping, "locale is null OR locale = id" escapes the surrounding
    // conditions and puts every language-neutral blueprint back in the list —
    // including ones that are unpublished or not sold in that country.
    expect(agentLangCatalogFor(agentLangTenant('ID')))->toBe([]);
});

it('keeps both gates: the right language is still not enough without a price', function () {
    agentLangIndonesia();

    agentLangBlueprint('Layanan', 'id', ['BR' => 4990]);

    expect(agentLangCatalogFor(agentLangTenant('ID')))->toBe([]);
});

it('shows every language to a caller that has not said who is asking', function () {
    agentLangIndonesia();

    agentLangBlueprint('Atendimento', 'pt_BR', ['BR' => 4990]);
    agentLangBlueprint('Layanan', 'id', ['BR' => 4990]);

    // The Back Office lists the whole catalog; narrowing there would hide rows
    // from the only screen that can edit them.
    $all = TrainedAgentBlueprint::query()->writtenIn(null)->pluck('name')->all();

    expect($all)->toContain('Atendimento', 'Layanan');
});

it('still hides an unpublished agent from its own language', function () {
    agentLangIndonesia();

    $draft = agentLangBlueprint('Rascunho', 'id', ['ID' => 14900000]);
    $draft->forceFill(['is_public' => false])->save();

    expect(agentLangCatalogFor(agentLangTenant('ID')))->toBe([]);
});
