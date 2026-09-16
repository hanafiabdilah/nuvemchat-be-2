<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How each currency is written
    |--------------------------------------------------------------------------
    |
    | Amounts are stored in minor units ("cents") everywhere in this codebase,
    | including for currencies nobody quotes in minor units: Rp 149.000 is
    | stored as 14900000. `decimals` is how many of those to show, not how many
    | the column holds — the rupiah is written without them, so a price shown
    | with "..,00" reads as a foreign habit.
    |
    | Deliberately a small table rather than ext-intl: the production image is
    | not guaranteed to carry intl (see App\Support\Market\CountryCatalog), and
    | this is read on notification paths where a missing extension would turn a
    | price into an exception. A currency that is not listed still formats —
    | as "IDR 149.000,00" — so selling in a new country never waits on a deploy.
    |
    */

    'currencies' => [
        'BRL' => ['symbol' => 'R$', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','],
        'IDR' => ['symbol' => 'Rp', 'decimals' => 0, 'thousands' => '.', 'decimal' => ','],
        'USD' => ['symbol' => '$', 'decimals' => 2, 'thousands' => ',', 'decimal' => '.'],
        'EUR' => ['symbol' => '€', 'decimals' => 2, 'thousands' => '.', 'decimal' => ','],
        'GBP' => ['symbol' => '£', 'decimals' => 2, 'thousands' => ',', 'decimal' => '.'],
        'MXN' => ['symbol' => '$', 'decimals' => 2, 'thousands' => ',', 'decimal' => '.'],
    ],

    /*
    | Anything not listed above: the ISO code in front of the number, which
    | says what the money is without pretending to know how the country writes
    | it.
    */

    'fallback' => ['decimals' => 2, 'thousands' => '.', 'decimal' => ','],

    /*
    |--------------------------------------------------------------------------
    | Exchange rates — units of the currency per 1 USD
    |--------------------------------------------------------------------------
    |
    | Defaults only. The live values are rows in the `settings` table, edited in
    | the Back Office, and read through App\Services\Money\ExchangeRates — never
    | with config() directly.
    |
    | Per USD rather than per BRL because that is the shape the arithmetic
    | already had: a run's cost arrives from the AI hub in dollars, and pricing
    | it has always been `cost_usd × rate × (1 + markup)`. Selling in a second
    | country changes which rate, not the formula. BRL's entry is the same
    | number `ai_credits.usd_brl_rate` has always held, and that setting stays
    | its source so nothing an admin already typed is lost.
    |
    | A fixed, quoted rate, not a feed — for the reason spelled out in
    | config/ai.php: a balance whose purchasing power moves during the day
    | cannot be reasoned about, and every debit stores the rate it used.
    |
    */

    'rates' => [
        'USD' => 1.0,
        'BRL' => (float) env('AI_CREDITS_USD_BRL_RATE', 5.60),
        'IDR' => (float) env('MONEY_USD_IDR_RATE', 16300),
    ],

];
