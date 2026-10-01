<?php

namespace App\Support;

/**
 * Writing an amount out, in the currency it is actually in.
 *
 * Every price, balance and invoice in this codebase is an integer of minor
 * units. Until markets, printing one meant `'R$ '.number_format($cents / 100,
 * 2, ',', '.')` — a string that is correct in exactly one country and is
 * repeated in eight places (notifications, renewal warnings, refunds). An
 * Indonesian workspace reading "R$ 149.000,00" for its own rupiah is not a
 * cosmetic problem: it is the platform telling a customer the wrong price.
 *
 * So the symbol, the separators and the number of decimals are properties of
 * the currency (config/money.php), not of this file, and a currency nobody
 * configured still prints — with its ISO code in front.
 *
 * Deliberately not ext-intl: the production image is not guaranteed to carry
 * it, and this runs on notification paths where a missing extension would turn
 * a price into an exception.
 */
final class Money
{
    /**
     * "R$ 1.234,56" · "Rp 149.000" · "IDR 149.000,00" for an unlisted currency.
     *
     * A null or empty currency is written as a bare number rather than guessed:
     * the caller that lost the currency is the bug, and inventing R$ here is
     * what hides it.
     */
    public static function format(int $cents, ?string $currency): string
    {
        $format = self::formatFor($currency);
        $code = self::normalize($currency);

        $number = number_format(
            $cents / 100,
            $format['decimals'],
            $format['decimal'],
            $format['thousands'],
        );

        if ($code === null) {
            return $number;
        }

        return isset($format['symbol'])
            ? $format['symbol'].' '.$number
            : $code.' '.$number;
    }

    /** How many decimals this currency is written with (0 for the rupiah). */
    public static function decimals(?string $currency): int
    {
        return (int) self::formatFor($currency)['decimals'];
    }

    /** The currency's symbol, or null when it is written with its ISO code. */
    public static function symbol(?string $currency): ?string
    {
        return self::formatFor($currency)['symbol'] ?? null;
    }

    /**
     * The nearest "round" amount on the 1–2–5 series: 20.000, 50.000, 100.000,
     * 200.000, 500.000…
     *
     * For buttons that offer an amount rather than charge one already priced:
     * a converted preset lands on Rp 64.837, and nobody picks a top-up that
     * reads like arithmetic. Nearest on a log scale, not up — these are
     * suggestions, and the customer can still type any amount they like.
     */
    public static function niceRound(int $cents): int
    {
        if ($cents <= 0) {
            return $cents;
        }

        $magnitude = 10 ** (int) floor(log10($cents));
        $best = $magnitude;

        foreach ([1, 2, 5, 10] as $step) {
            $candidate = $step * $magnitude;

            if (abs(log($candidate / $cents)) < abs(log($best / $cents))) {
                $best = $candidate;
            }
        }

        return (int) $best;
    }

    /** The next amount on the 1–2–5 series strictly above `$cents`. */
    public static function niceNext(int $cents): int
    {
        $magnitude = 10 ** (int) floor(log10(max(1, $cents)));

        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $magnitude > $cents) {
                return (int) ($step * $magnitude);
            }
        }

        return (int) (10 * $magnitude);
    }

    /**
     * Round **up** to a number of significant digits: 31.234 → 32.000.
     *
     * For floors (a minimum top-up) that came out of a conversion: they must
     * not drop below the amount the platform set, and the 1–2–5 series would
     * push them up by as much as two-and-a-half times.
     */
    public static function roundUpSignificant(int $cents, int $digits = 2): int
    {
        if ($cents <= 0) {
            return $cents;
        }

        $step = 10 ** max(0, (int) floor(log10($cents)) + 1 - $digits);

        return (int) (ceil($cents / $step) * $step);
    }

    /**
     * Round an amount **up** to a step, for prices that came out of a
     * conversion.
     *
     * A converted price lands on Rp 148.637, and no one prices anything that
     * way; the market's step (config in the market row) turns it into
     * Rp 149.000. Up rather than to-nearest because rounding down sells below
     * the price the platform set, every time, for the life of the market.
     *
     * A step of 1 or less leaves the amount alone, which is what BRL wants.
     */
    public static function roundUpTo(int $cents, int $stepCents): int
    {
        if ($stepCents <= 1 || $cents <= 0) {
            return $cents;
        }

        return (int) (ceil($cents / $stepCents) * $stepCents);
    }

    /** @return array{symbol?: string, decimals: int, thousands: string, decimal: string} */
    private static function formatFor(?string $currency): array
    {
        $code = self::normalize($currency);

        /** @var array<string, array<string, mixed>> $currencies */
        $currencies = config('money.currencies', []);

        /** @var array<string, mixed> $fallback */
        $fallback = config('money.fallback', ['decimals' => 2, 'thousands' => '.', 'decimal' => ',']);

        // @phpstan-ignore-next-line the shapes come from config
        return $code !== null && isset($currencies[$code]) ? $currencies[$code] : $fallback;
    }

    private static function normalize(?string $currency): ?string
    {
        $code = strtoupper(trim((string) $currency));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }
}
