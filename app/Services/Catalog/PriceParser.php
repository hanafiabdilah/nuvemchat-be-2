<?php

namespace App\Services\Catalog;

/**
 * A price as a shop writes it in a spreadsheet, in minor units.
 *
 * "59,90", "R$ 1.234,56", "Rp 149.000", "1,234.56", "59.9", plain "60". The
 * rule is the Payment node's (PaymentNodes::parseAmount): when both separators
 * appear the last one is the decimal point; a lone dot or comma followed by
 * exactly three digits is a thousands separator. It differs in two ways a
 * catalog needs: any currency word or symbol is stripped, not only "R$", and
 * zero is a price (a free sample is still a product).
 */
final class PriceParser
{
    /** R$ 10 billion / Rp 100 billion — past that it is a typo, not a price. */
    public const MAX_CENTS = 1_000_000_000_000;

    public static function cents(string $raw): ?int
    {
        $value = (string) preg_replace('/[^\d.,]/u', '', $raw);

        if ($value === '' || preg_match('/^\d[\d.,]*$/', $value) !== 1) {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $value = str_replace([$thousands, $decimal], ['', '.'], $value);
        } elseif ($lastComma !== false) {
            $decimals = strlen($value) - $lastComma - 1;
            $value = substr_count($value, ',') > 1 || $decimals === 3
                ? str_replace(',', '', $value)
                : str_replace(',', '.', $value);
        } elseif ($lastDot !== false) {
            $decimals = strlen($value) - $lastDot - 1;
            if (substr_count($value, '.') > 1 || $decimals === 3) {
                $value = str_replace('.', '', $value);
            }
        }

        if (! is_numeric($value)) {
            return null;
        }

        $cents = (int) round(((float) $value) * 100);

        return $cents >= 0 && $cents <= self::MAX_CENTS ? $cents : null;
    }
}
