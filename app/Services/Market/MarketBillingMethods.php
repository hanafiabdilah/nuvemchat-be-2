<?php

namespace App\Services\Market;

use App\Enums\Billing\PaymentMethod;

/**
 * Which ways the platform can be paid in a country.
 *
 * A fact about the country, never about the plan. Pix is a Brazilian rail; a
 * card is a card anywhere. The plan-level answer — `market_prices.pix_enabled`
 * — is a commercial decision layered on top, and it only means anything where
 * the rail is here to decide about.
 *
 * Three places in the codebase already wrote down that Pix is Brazil-only (the
 * cast on MarketPrice, the price editor, the plan form) and all three stopped at
 * "so make it per-country". None of them went on to "so do not offer it where
 * the rail is absent", which is why an Indonesian plan had a Pix checkbox and
 * why every backfilled market price row claims Pix is on.
 *
 * ⚠️ Config-only, deliberately — no `markets` column and no Back Office editor,
 * unlike MarketDocuments. A document list varies by what a country's rails ask
 * for and an admin genuinely knows better than we do; whether Pix reaches
 * Indonesia is not an opinion, and a toggle for it could only ever be used to
 * record something false. The cost of being wrong is asymmetric too: a missing
 * document type is a form somebody fixes, a missing rail is a charge that dies
 * in front of a paying customer.
 *
 * This answers "does the rail exist", not "is our gateway able to use it
 * today". The second question belongs to the payment service and is asked, per
 * currency, by BillingController::paymentMethods(). Both gates matter: this one
 * stops us offering the impossible, that one stops us offering the unavailable.
 */
final class MarketBillingMethods
{
    /**
     * The rails a country has, as PaymentMethod values.
     *
     * @return list<string>
     */
    public static function forMarket(?string $marketCode): array
    {
        $code = strtoupper(trim((string) $marketCode));

        if ($code === '') {
            $code = MarketResolver::defaultCode();
        }

        /** @var array<string, list<string>> $map */
        $map = config('markets.billing_methods', []);

        $methods = $map[$code] ?? config('markets.default_billing_methods', ['card']);

        return self::sanitize($methods);
    }

    /**
     * Whether a country can be paid this way at all.
     *
     * `manual` is always true: a comp or an off-system invoice is an admin
     * granting a plan, not a rail — gating it on geography would lock the one
     * route that still works in a country whose payments are not live yet.
     */
    public static function has(?string $marketCode, string $method): bool
    {
        $method = strtolower(trim($method));

        if ($method === PaymentMethod::Manual->value) {
            return true;
        }

        return in_array($method, self::forMarket($marketCode), true);
    }

    /**
     * Drop anything that is not a payment method we actually implement, so a
     * typo in config becomes one missing option rather than a checkout offering
     * a button no handler answers.
     *
     * @param  array<int, mixed>  $methods
     * @return list<string>
     */
    private static function sanitize(array $methods): array
    {
        $known = array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases());
        $clean = [];

        foreach ($methods as $method) {
            $value = strtolower(trim((string) $method));

            // Manual is not a rail — it is the absence of one — so it never
            // belongs in a country's list even if somebody writes it there.
            if ($value === PaymentMethod::Manual->value) {
                continue;
            }

            if (in_array($value, $known, true) && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }
}
