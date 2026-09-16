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
