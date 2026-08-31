<?php

namespace App\Services\AiCredits;

use App\Models\Setting;

/**
 * What a run costs the customer, and where those two numbers come from.
 *
 * The commercial settings (markup and FX rate) live in the `settings` table so
 * the Back Office can move them without a deploy — the same pattern as the
 * MercadoPago and ProxyBR credentials — and fall back to config/ai.php until an
 * admin has ever touched them.
 *
 * Nothing here reads a wallet or writes a row. It exists so that the one piece
 * of arithmetic that decides how much money changes hands has a single home,
 * and so a test can state the price of a run in one line.
 */
class AiCreditPricing
{
    public const KEY_MARKUP_PCT = 'ai_credits.markup_pct';
    public const KEY_USD_BRL_RATE = 'ai_credits.usd_brl_rate';

    /** Markup on the provider's cost, in percent. */
    public static function markupPct(): float
    {
        $setting = Setting::get(self::KEY_MARKUP_PCT);

        return is_numeric($setting)
            ? (float) $setting
            : (float) config('ai.credits.markup_pct', 40);
    }

    /** USD → BRL, fixed. See config/ai.php for why it is not a live feed. */
    public static function usdBrlRate(): float
    {
        $setting = Setting::get(self::KEY_USD_BRL_RATE);
        $rate = is_numeric($setting)
            ? (float) $setting
            : (float) config('ai.credits.usd_brl_rate', 5.60);

        // A rate of zero would price every run at the fallback and look like
        // the hub had stopped reporting cost — a misconfiguration that hides
        // as a different problem is worse than one that refuses to apply.
        return $rate > 0 ? $rate : (float) config('ai.credits.usd_brl_rate', 5.60);
    }

    /**
     * Price one run, in BRL cents.
     *
     * `$costUsd` is what the hub said the provider charged. Null means the hub
     * reported nothing — an older deployment, or a run whose stage does not
     * price itself — and those must not be free: see `fallback_run_cents` in
     * config/ai.php. The caller is expected to log when it lands there.
     *
     * Rounded up, always. The alternative is that a very cheap run costs zero,
     * and a workspace can then hold an empty wallet and keep talking forever.
     *
     * @return array{cents: int, cost_usd: float|null, rate: float, markup_pct: float, estimated: bool}
     */
    public static function priceRun(?float $costUsd): array
    {
        $rate = self::usdBrlRate();
        $markup = self::markupPct();

        if ($costUsd === null || $costUsd <= 0) {
            return [
                'cents' => max(0, (int) config('ai.credits.fallback_run_cents', 5)),
                'cost_usd' => $costUsd,
                'rate' => $rate,
                'markup_pct' => $markup,
                'estimated' => true,
            ];
        }

        // Rounded to six places *before* the ceiling, and that is not cosmetic:
        // 0.02 × 5 × 1.5 is 0.15000000000000002 in binary floating point, and
        // ceiling that gives 16 cents instead of 15. Without this every single
        // price would quietly carry an extra cent it cannot justify.
        $brl = round($costUsd * $rate * (1 + $markup / 100), 6);

        return [
            'cents' => max(1, (int) ceil(round($brl * 100, 6))),
            'cost_usd' => $costUsd,
            'rate' => $rate,
            'markup_pct' => $markup,
            'estimated' => false,
        ];
    }
}
