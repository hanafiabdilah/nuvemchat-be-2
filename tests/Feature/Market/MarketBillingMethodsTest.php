<?php

use App\Models\Market;
use App\Models\MarketPrice;
use App\Services\Market\MarketBillingMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Declared per file: the binding in tests/Pest.php is commented out, so a file
// that leaves this off runs against no schema at all.
uses(RefreshDatabase::class);

/**
 * Pix is a Brazilian rail. Three places in the codebase said so in a comment and
 * none of them acted on it, so the Back Office drew a Pix checkbox for every
 * country and the Fase 3 backfill wrote `pix_enabled = true` onto every market
 * price row — Indonesia included.
 *
 * ⚠️ Helpers here are named for this file. Pest loads every test file into one
 * process, so a shared name is a fatal redeclare.
 */
function billingMethodsMarket(string $code, string $currency): Market
{
    return Market::query()->create([
        'code' => $code,
        'name' => $code,
        'currency' => $currency,
        'default_locale' => 'en',
        'default_timezone' => 'UTC',
        'phone_country' => '1',
        'status' => 'active',
    ]);
}

it('knows Pix reaches Brazil and not Indonesia', function () {
    expect(MarketBillingMethods::has('BR', 'pix'))->toBeTrue()
        ->and(MarketBillingMethods::has('BR', 'card'))->toBeTrue()
        ->and(MarketBillingMethods::has('ID', 'pix'))->toBeFalse()
        ->and(MarketBillingMethods::has('ID', 'card'))->toBeTrue();
});

it('offers only cards in a country nobody has written down', function () {
    expect(MarketBillingMethods::forMarket('JP'))->toBe(['card'])
        ->and(MarketBillingMethods::has('JP', 'pix'))->toBeFalse();
});

it('always allows manual, which is the absence of a rail rather than one', function () {
    // A comp or an off-system invoice is an admin granting a plan. Gating it on
    // geography would lock the one route that still works in a country whose
    // payments are not live yet.
    expect(MarketBillingMethods::has('ID', 'manual'))->toBeTrue()
        ->and(MarketBillingMethods::forMarket('BR'))->not->toContain('manual');
});

it('reads a backfilled Indonesian Pix flag back as false', function () {
    billingMethodsMarket('ID', 'IDR');

    // Exactly what the Fase 3 backfill left behind: the row claims Pix is on.
    $price = MarketPrice::query()->create([
        'priceable_type' => 'App\Models\Plan',
        'priceable_id' => 1,
        'market_code' => 'ID',
        'amount_cents' => 14900000,
        'currency' => 'IDR',
        'card_enabled' => true,
        'pix_enabled' => true,
    ]);

    // The stored column still says 1; every reader must be told otherwise —
    // BillingService::subscribe() reads this property directly.
    expect($price->fresh()->pix_enabled)->toBeFalse()
        ->and($price->fresh()->card_enabled)->toBeTrue();
});

it('leaves a Brazilian Pix flag alone', function () {
    $price = MarketPrice::query()->create([
        'priceable_type' => 'App\Models\Plan',
        'priceable_id' => 2,
        'market_code' => 'BR',
        'amount_cents' => 4990,
        'currency' => 'BRL',
        'card_enabled' => true,
        'pix_enabled' => true,
    ]);

    expect($price->fresh()->pix_enabled)->toBeTrue();
});

it('still reports a rail off when the admin turned it off where it exists', function () {
    $price = MarketPrice::query()->create([
        'priceable_type' => 'App\Models\Plan',
        'priceable_id' => 3,
        'market_code' => 'BR',
        'amount_cents' => 4990,
        'currency' => 'BRL',
        'card_enabled' => true,
        'pix_enabled' => false,
    ]);

    // The rail existing never turns a decision back on.
    expect($price->fresh()->pix_enabled)->toBeFalse();
});
