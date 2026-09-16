<?php

namespace App\Services\Money;

use App\Models\Market;
use App\Models\Tenant;
use App\Services\Market\MarketResolver;

/**
 * A price the platform set once, expressed in a customer's own money.
 *
 * Two kinds of price exist in this product and only one of them belongs here.
 * A plan and a trained agent are priced **per country by hand** — a market row
 * decides, and no rate is involved (see HasMarketPrices). Everything the
 * platform sells out of the prepaid balance is priced **once, centrally**: an
 * API Way instance costs what ProxyBR quotes, a gigabyte of gallery storage
 * costs what the Back Office typed, an AI run costs what the provider charged.
 * Those are the ones converted here.
 *
 * The base is the home market's currency (reais), because that is the currency
 * those numbers are already written in. AI runs are the exception that proves
 * it: their cost arrives in dollars and is priced straight into the customer's
 * currency by CreditPricing, never through reais.
 *
 * Null means "cannot be quoted here" — no rate for that currency. Every caller
 * has to be able to say so rather than publish a number it invented; that is
 * the whole reason this returns a nullable int.
 */
final class MarketMoney
{
    /** The currency the platform's own prices are written in. */
    public static function baseCurrency(): string
    {
        return Market::query()->whereKey(MarketResolver::defaultCode())->value('currency') ?: 'BRL';
    }

    /**
     * A platform price in the workspace's money, rounded up to the market's
     * step (Rp 148.637 → Rp 149.000).
     */
    public static function forTenant(int $cents, Tenant $tenant): ?int
    {
        return self::forMarket($cents, $tenant->market);
    }

    public static function forMarket(int $cents, ?Market $market): ?int
    {
        $currency = $market?->currency ?: self::baseCurrency();

        if (strtoupper($currency) === strtoupper(self::baseCurrency())) {
            return $cents;
        }

        return ExchangeRates::convert(
            $cents,
            self::baseCurrency(),
            $currency,
            $market?->roundingCents() ?? 1,
        );
    }

    /**
     * Same, but never null: the base amount is returned when the currency
     * cannot be quoted.
     *
     * For the two places where refusing is worse than being approximate — a
     * warning threshold and a minimum — because a missing rate must not stop a
     * customer topping up or silence a low-balance warning. Anything that
     * *charges* uses forTenant() and refuses.
     */
    public static function orBase(int $cents, Tenant $tenant): int
    {
        return self::forTenant($cents, $tenant) ?? $cents;
    }

    /**
     * Whether a platform price can be expressed in this workspace's money at all.
     *
     * ⚠️ Ask this before selling anything priced centrally. Without a rate for
     * the market's currency, `orBase()` hands the base number straight back — so
     * an instance quoted at R$ 39,90 reaches an Indonesian customer as
     * "Rp 39,90", and the charge is forty rupiah. A missing rate is an admin who
     * has not filled in Markets → Exchange rates yet, and the honest answer to
     * every price until then is "not sold here", never a number.
     */
    public static function quotableFor(?Tenant $tenant): bool
    {
        if ($tenant === null) {
            return true;
        }

        // A round base amount is enough to know whether the pair converts.
        return self::forMarket(100, $tenant->market) !== null;
    }
}
