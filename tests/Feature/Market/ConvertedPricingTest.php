<?php

use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Credits\CreditPricing;
use App\Services\Gallery\GalleryRentalService;
use App\Services\Money\ExchangeRates;
use App\Services\Money\MarketMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Prices the platform sets once and converts: an AI run, a gigabyte of gallery
 * storage, an API Way instance. Unlike a plan, nobody types these per country —
 * see HasMarketPrices for the ones that are.
 */
beforeEach(function () {
    Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        // Rp 1.000: nobody prices anything at Rp 148.637.
        'price_rounding_cents' => 100000,
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);

    CreditPricing::store(['usd_brl_rate' => 5.00]);
    ExchangeRates::store(['IDR' => 16000]);
});

function convertedWorkspace(string $marketCode): Tenant
{
    $owner = User::factory()->create();

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

it('prices an AI run straight from dollars into the workspace currency', function () {
    // US$0.01 × 16.000 × 1,40 markup = Rp 224.
    $indonesian = CreditPricing::priceRun(0.01, null, null, 'IDR');

    expect($indonesian['cents'])->toBe(22400)
        ->and($indonesian['currency'])->toBe('IDR')
        ->and($indonesian['rate'])->toBe(16000.0)
        ->and($indonesian['rate_missing'])->toBeFalse()
        // The same run in the home market: US$0.01 × 5 × 1,40 = R$ 0,07.
        ->and(CreditPricing::priceRun(0.01, null, null, 'BRL')['cents'])->toBe(7);
});

it('prices at the home rate and says so when a currency has no rate', function () {
    $price = CreditPricing::priceRun(0.01, null, null, 'XOF');

    // Charged rather than given away: the run happened and the provider billed
    // for it. The flag is what makes the misconfiguration visible.
    expect($price['rate_missing'])->toBeTrue()
        ->and($price['rate'])->toBe(5.0)
        ->and($price['cents'])->toBe(7);
});

it('states the top-up floor and the low-balance warning in the workspace currency', function () {
    // R$ 10,00 → US$2 → Rp 32.000.
    expect(CreditPricing::minTopupCents('IDR'))->toBe(3200000)
        ->and(CreditPricing::minTopupCents('BRL'))->toBe(1000)
        // R$ 5,00 → Rp 16.000.
        ->and(CreditPricing::lowBalanceCents('IDR'))->toBe(1600000);
});

it('converts a platform price and rounds it up to the market step', function () {
    $tenant = convertedWorkspace('ID');

    // R$ 1,90 per GB → US$0,38 → Rp 6.080, rounded up to Rp 7.000.
    expect(MarketMoney::forTenant(190, $tenant))->toBe(700000)
        // The home market pays the number that was typed, untouched.
        ->and(MarketMoney::forTenant(190, convertedWorkspace('BR')))->toBe(190);
});

it('quotes gallery storage in the workspace currency', function () {
    $quote = app(GalleryRentalService::class)->quote(convertedWorkspace('ID'), 3);

    expect($quote['price_per_gb_cents'])->toBe(700000)
        ->and($quote['monthly_cents'])->toBe(2100000)
        ->and($quote['charge_now_cents'])->toBe(2100000);
});

it('leaves a platform price alone when the workspace is in the home market', function () {
    $quote = app(GalleryRentalService::class)->quote(convertedWorkspace('BR'), 3);

    expect($quote['price_per_gb_cents'])->toBe(190)
        ->and($quote['monthly_cents'])->toBe(570);
});
