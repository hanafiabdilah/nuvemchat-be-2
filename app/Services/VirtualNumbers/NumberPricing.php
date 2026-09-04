<?php

namespace App\Services\VirtualNumbers;

use App\Models\Setting;

/**
 * What a virtual number costs us and what we sell it for.
 *
 * API Way publishes one monthly `price_cents` for every number in the catalog;
 * the resale price is ours to set ("a precificação ao cliente final é sua").
 * Both halves of that decision live here so the price quoted on the tenant's
 * screen and the price charged to their balance can never be computed two
 * different ways.
 *
 * A percentage over cost is the default because it is the only form that stays
 * correct on its own: API Way raises the catalog and the margin survives
 * without anybody noticing there was something to change. The per-app override
 * exists for the other half of the truth — a round R$ 49,90 sells better than
 * R$ 46,06, and some apps are worth more than the same number costs — and when
 * one is set it wins outright.
 *
 * ⚠️ An override is a fixed price, not a floor. If API Way's cost ever passes
 * it, the sale is at a loss and nothing here will stop it; that is what
 * `marginCents()` is for, and why the Back Office editor prints the cost next to
 * every override field.
 */
class NumberPricing
{
    public const KEY_MARKUP_PCT = 'apiway_numbers.markup_pct';

    /** JSON map of app id → fixed sale price in cents. */
    public const KEY_APP_PRICES = 'apiway_numbers.app_prices';

    /** Markup applied to the catalog cost when an app has no fixed price. */
    public const DEFAULT_MARKUP_PCT = 40.0;

    public static function markupPct(): float
    {
        $value = Setting::get(self::KEY_MARKUP_PCT);

        return is_numeric($value) ? max(0.0, (float) $value) : self::DEFAULT_MARKUP_PCT;
    }

    /**
     * Fixed per-app sale prices, in cents.
     *
     * @return array<string, int>
     */
    public static function appPrices(): array
    {
        $raw = Setting::get(self::KEY_APP_PRICES);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $prices = [];

        foreach ($decoded as $app => $cents) {
            if (is_numeric($cents) && (int) $cents > 0) {
                $prices[(string) $app] = (int) $cents;
            }
        }

        return $prices;
    }

    /**
     * The price a tenant pays for one month of a number of this app.
     *
     * Rounded up to the cent: rounding a resale price down is the platform
     * paying a fraction of every sale for the privilege of a tidier number.
     */
    public static function saleCents(string $app, int $costCents): int
    {
        $override = self::appPrices()[$app] ?? null;

        if ($override !== null) {
            return $override;
        }

        return (int) ceil(round($costCents * (1 + self::markupPct() / 100), 4));
    }

    /** What the platform keeps on this sale. Negative means an override sells at a loss. */
    public static function marginCents(string $app, int $costCents): int
    {
        return self::saleCents($app, $costCents) - $costCents;
    }

    /**
     * @return array{markup_pct: float, app_prices: array<string, int>}
     */
    public static function settings(): array
    {
        return [
            'markup_pct' => self::markupPct(),
            'app_prices' => self::appPrices(),
        ];
    }

    /**
     * Store the commercial block. Only the keys present are written, so a save
     * that carries just the markup leaves the overrides alone.
     *
     * An override of null or 0 removes that app's fixed price rather than
     * selling it for nothing — a field cleared in the editor means "go back to
     * the markup", which is the only reading that is ever intended.
     *
     * @param  array{markup_pct?: mixed, app_prices?: array<string, mixed>}  $values
     */
    public static function store(array $values): void
    {
        if (array_key_exists('markup_pct', $values) && is_numeric($values['markup_pct'])) {
            Setting::set(self::KEY_MARKUP_PCT, (string) max(0.0, (float) $values['markup_pct']));
        }

        if (array_key_exists('app_prices', $values) && is_array($values['app_prices'])) {
            $prices = [];

            foreach ($values['app_prices'] as $app => $cents) {
                if (is_numeric($cents) && (int) $cents > 0) {
                    $prices[(string) $app] = (int) $cents;
                }
            }

            Setting::set(self::KEY_APP_PRICES, json_encode($prices));
        }
    }
}
