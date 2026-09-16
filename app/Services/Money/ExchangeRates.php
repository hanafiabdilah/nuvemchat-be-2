<?php

namespace App\Services\Money;

use App\Models\Setting;
use App\Services\Credits\CreditPricing;
use App\Support\Money;

/**
 * What one currency is worth in another, for a platform that sells the same
 * things in several countries.
 *
 * One number per currency: **how many of its units one US dollar buys**. That
 * is the shape the arithmetic already had — a run's cost arrives from the AI
 * hub in dollars and has always been priced `cost_usd × rate × (1 + markup)` —
 * so selling in a second country changes which rate is read, not the formula.
 * Converting between two non-dollar currencies goes through USD, which is also
 * how the only upstream costs the platform pays (ProxyBR, API Way) end up
 * quotable in a customer's own money.
 *
 * BRL deliberately has no rate of its own here: it reads
 * `ai_credits.usd_brl_rate`, the row an admin has been setting since the
 * prepaid balance shipped. A second key holding the same number is a second
 * number to keep right, and the day they disagree every historical price is
 * suddenly unexplainable.
 *
 * ⚠️ Fixed rates, quoted by whoever sets the prices — not a feed. The reasoning
 * is in config/ai.php and it did not change with markets: a balance whose
 * purchasing power moves during the day cannot be reasoned about by the person
 * holding it, and the drift would arrive in the ledger as unexplained variation
 * between two identical runs. Every debit already copies the rate it used onto
 * its own row, so raising a rate never rewrites what an old charge meant.
 */
final class ExchangeRates
{
    /** Per-currency rates an admin has set: {"IDR": 16300}. BRL is not stored here. */
    public const KEY_RATES = 'money.rates';

    /** Everything is quoted against this one. */
    public const BASE = 'USD';

    /**
     * Units of `$currency` per 1 USD.
     *
     * Zero or missing falls back to the configured default rather than to 1:
     * a rate of 1 would price a rupiah like a dollar, and it would do it
     * silently, on every sale, until somebody checked an invoice.
     */
    public static function perUsd(string $currency): float
    {
        $code = self::normalize($currency);

        if ($code === self::BASE) {
            return 1.0;
        }

        // The rate an admin has been setting all along — see the class note.
        if ($code === 'BRL') {
            return CreditPricing::usdBrlRate();
        }

        $stored = self::stored()[$code] ?? null;
        $rate = is_numeric($stored) ? (float) $stored : 0.0;

        if ($rate > 0) {
            return $rate;
        }

        return max(0.0, (float) config("money.rates.{$code}", 0));
    }

    /** Whether a price can be quoted in this currency at all. */
    public static function has(string $currency): bool
    {
        return self::perUsd($currency) > 0;
    }

    /**
     * Convert an amount in minor units between two currencies.
     *
     * Rounded **up**, like every other price in this codebase: the alternative
     * is selling a fraction below the price the platform set, on every sale,
     * for the life of the market.
     *
     * `$stepCents` rounds the result up to something a person would write on a
     * price tag (Rp 149.000 rather than Rp 148.637) — it is the market's own
     * step, see `markets.price_rounding_cents`.
     *
     * Returns null when either side has no usable rate, so the caller can say
     * "not priced here" instead of quoting a number it invented.
     */
    public static function convert(int $cents, string $from, string $to, int $stepCents = 1): ?int
    {
        $from = self::normalize($from);
        $to = self::normalize($to);

        if ($from === $to) {
            return $cents;
        }

        $fromRate = self::perUsd($from);
        $toRate = self::perUsd($to);

        if ($fromRate <= 0 || $toRate <= 0) {
            return null;
        }

        if ($cents === 0) {
            return 0;
        }

        // Six places before the ceiling, for the reason CreditPricing::priceRun
        // spells out: binary floating point turns an exact price into one that
        // is a hair above it, and the ceiling then charges a whole extra unit.
        $converted = round($cents / $fromRate * $toRate, 6);

        return Money::roundUpTo((int) ceil(round($converted, 6)), $stepCents);
    }

    /**
     * Every rate the platform holds, including the one BRL borrows.
     *
     * @return array<string, float>
     */
    public static function all(): array
    {
        $rates = [self::BASE => 1.0, 'BRL' => CreditPricing::usdBrlRate()];

        foreach (self::stored() as $code => $value) {
            $code = self::normalize((string) $code);

            if ($code !== '' && is_numeric($value) && (float) $value > 0) {
                $rates[$code] = (float) $value;
            }
        }

        foreach ((array) config('money.rates', []) as $code => $value) {
            $code = self::normalize((string) $code);

            if ($code !== '' && ! isset($rates[$code]) && (float) $value > 0) {
                $rates[$code] = (float) $value;
            }
        }

        ksort($rates);

        return $rates;
    }

    /**
     * Write the rates an admin typed.
     *
     * BRL is routed to `ai_credits.usd_brl_rate` so both screens keep moving
     * the same number, and USD is refused outright: a dollar is one dollar, and
     * a stored rate for it would be a second, editable definition of the unit
     * everything else is measured in.
     *
     * @param  array<string, mixed>  $rates  currency code => units per USD
     */
    public static function store(array $rates): void
    {
        $existing = self::stored();

        foreach ($rates as $code => $value) {
            $code = self::normalize((string) $code);

            if ($code === '' || $code === self::BASE || ! is_numeric($value) || (float) $value <= 0) {
                continue;
            }

            if ($code === 'BRL') {
                CreditPricing::store(['usd_brl_rate' => (float) $value]);

                continue;
            }

            $existing[$code] = (float) $value;
        }

        Setting::set(self::KEY_RATES, json_encode($existing, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, float> */
    private static function stored(): array
    {
        $raw = Setting::get(self::KEY_RATES);

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function normalize(string $currency): string
    {
        $code = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : '';
    }
}
