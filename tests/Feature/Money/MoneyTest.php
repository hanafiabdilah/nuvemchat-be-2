<?php

use App\Models\CreditWallet;
use App\Models\Market;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Credits\CreditPricing;
use App\Services\Credits\CreditService;
use App\Services\Money\ExchangeRates;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function moneyMarket(string $code = 'ID', string $currency = 'IDR', int $rounding = 1): Market
{
    return Market::create([
        'code' => $code,
        'name' => 'Indonesia',
        'currency' => $currency,
        'price_rounding_cents' => $rounding,
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'active',
    ]);
}

function moneyWorkspace(string $marketCode): Tenant
{
    $owner = User::factory()->create();

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $marketCode;
    $tenant->save();

    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

it('writes an amount the way its own currency is written', function () {
    expect(Money::format(149900, 'BRL'))->toBe('R$ 1.499,00')
        // The rupiah is not written with its minor unit, so a price shown with
        // ",00" reads as somebody else's habit.
        ->and(Money::format(14900000, 'IDR'))->toBe('Rp 149.000')
        ->and(Money::format(999, 'USD'))->toBe('$ 9.99')
        // A currency nobody configured still prints, with its code in front.
        ->and(Money::format(150000, 'PHP'))->toBe('PHP 1.500,00')
        // And a lost currency prints a bare number rather than inventing reais.
        ->and(Money::format(150000, null))->toBe('1.500,00');
});

it('rounds a converted price up to something a person would write', function () {
    expect(Money::roundUpTo(14863700, 100000))->toBe(14900000)
        ->and(Money::roundUpTo(14900000, 100000))->toBe(14900000)
        // A step of one is no rounding at all — what Brazil wants.
        ->and(Money::roundUpTo(4990, 1))->toBe(4990)
        ->and(Money::roundUpTo(0, 100000))->toBe(0);
});

it('reads the rate an admin has been setting all along for reais', function () {
    CreditPricing::store(['usd_brl_rate' => 5.25]);

    expect(ExchangeRates::perUsd('BRL'))->toBe(5.25)
        ->and(ExchangeRates::perUsd('USD'))->toBe(1.0)
        ->and(ExchangeRates::all()['BRL'])->toBe(5.25);
});

it('stores a rate per currency, routing reais to the setting that already holds it', function () {
    ExchangeRates::store(['IDR' => 16500, 'BRL' => 5.40, 'USD' => 9.9, 'zzz' => 3]);

    expect(ExchangeRates::perUsd('IDR'))->toBe(16500.0)
        // The dollar is the unit everything else is measured in; a stored rate
        // for it would be a second, editable definition of that unit.
        ->and(ExchangeRates::perUsd('USD'))->toBe(1.0)
        ->and(CreditPricing::usdBrlRate())->toBe(5.40)
        ->and(Setting::get(ExchangeRates::KEY_RATES))->not->toContain('USD');
});

it('refuses to quote a currency it has no rate for', function () {
    // Nothing stored and nothing in config: the caller has to be able to say
    // "not priced here" rather than publish a number it invented.
    expect(ExchangeRates::has('XOF'))->toBeFalse()
        ->and(ExchangeRates::convert(4990, 'BRL', 'XOF'))->toBeNull()
        ->and(ExchangeRates::has('IDR'))->toBeTrue();
});

it('converts through the dollar and rounds the result up', function () {
    CreditPricing::store(['usd_brl_rate' => 5.00]);
    ExchangeRates::store(['IDR' => 16000]);

    // R$ 49,90 → US$ 9.98 → Rp 159.680.
    expect(ExchangeRates::convert(4990, 'BRL', 'IDR'))->toBe(15968000)
        // With the market's step, the price tag reads Rp 160.000.
        ->and(ExchangeRates::convert(4990, 'BRL', 'IDR', 100000))->toBe(16000000)
        ->and(ExchangeRates::convert(4990, 'BRL', 'BRL'))->toBe(4990);
});

it('gives a workspace the currency of the market it belongs to', function () {
    moneyMarket();

    $brazilian = moneyWorkspace('BR');
    $indonesian = moneyWorkspace('ID');

    expect($brazilian->currency())->toBe('BRL')
        ->and($indonesian->currency())->toBe('IDR');
});

it('opens the prepaid balance in the workspace currency, not in reais', function () {
    moneyMarket();

    $credits = app(CreditService::class);

    expect($credits->wallet(moneyWorkspace('ID'))->currency)->toBe('IDR')
        ->and($credits->wallet(moneyWorkspace('BR'))->currency)->toBe('BRL');
});

it('keeps the balance in the currency it was opened in', function () {
    moneyMarket();
    $tenant = moneyWorkspace('ID');
    $credits = app(CreditService::class);

    $credits->wallet($tenant);
    // A market's currency cannot move under an existing workspace, but if a row
    // ever disagreed, the money on the ledger is what the customer holds.
    CreditWallet::where('tenant_id', $tenant->id)->update(['currency' => 'IDR']);

    expect($credits->currencyFor($tenant->fresh()))->toBe('IDR');
});

it('rounds nothing in a market whose prices were authored in its own currency', function () {
    expect(Market::findOrFail('BR')->roundingCents())->toBe(1)
        ->and(moneyMarket('MX', 'MXN', 0)->roundingCents())->toBe(1);
});
