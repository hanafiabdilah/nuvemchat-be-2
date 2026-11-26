<?php

namespace App\Services\Crm;

/**
 * A phone number as people save it in their own address book, made into one
 * that can be stored — for Brazil, where the way numbers are saved differs most
 * from the way they are dialled from elsewhere.
 *
 * Two habits, both everywhere in a Brazilian phone's contacts: a local number
 * saved without its area code (DDD) — "99975-8635" means the owner's own city —
 * and a number saved with the long-distance carrier code (0 15 79 99935-8064).
 * The second is fixed by dropping the carrier. The first cannot be fixed by
 * guessing: the area code comes from the person importing (the preview
 * suggests the one most numbers in the file have), and without it the phone is
 * left out instead of being stored as "55" + eight digits — a number that
 * belongs to nobody, or to somebody else.
 *
 * Every other country, and every number written with "+" or "00", passes
 * through untouched; CustomerService::canonicalPhone does the rest.
 */
final class ImportPhone
{
    private const BRAZIL = '55';

    /** Whether a typed number is a Brazilian local number with no area code. */
    public static function lacksAreaCode(string $typed, ?string $callingCode): bool
    {
        $national = self::national($typed, $callingCode);

        return $national !== null && in_array(strlen($national), [8, 9], true);
    }

    /** The area code a typed Brazilian number carries, if it carries one. */
    public static function areaCode(string $typed, ?string $callingCode): ?string
    {
        if (self::digits((string) $callingCode) !== self::BRAZIL) {
            return null;
        }

        $typed = trim($typed);
        $digits = self::digits($typed);

        if (str_starts_with($typed, '+') || str_starts_with($digits, '00')) {
            $digits = ltrim($digits, '0');

            return str_starts_with($digits, self::BRAZIL) && in_array(strlen($digits), [12, 13], true) ? self::valid(substr($digits, 2, 2)) : null;
        }

        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, self::BRAZIL)) {
            return self::valid(substr($digits, 2, 2));
        }

        $national = self::national($typed, $callingCode);

        return $national !== null && in_array(strlen($national), [10, 11], true) ? self::valid(substr($national, 0, 2)) : null;
    }

    /**
     * The number ready for canonicalPhone, or null when it is a local number
     * and there is no area code to put in front of it.
     */
    public static function prepare(string $typed, ?string $callingCode, ?string $areaCode): ?string
    {
        $national = self::national($typed, $callingCode);

        if ($national === null) {
            return trim($typed);
        }

        if (in_array(strlen($national), [8, 9], true)) {
            return $areaCode !== null && self::valid($areaCode) !== null ? $areaCode.$national : null;
        }

        return $national;
    }

    /**
     * A Brazilian number written the national way — no "+", no "00" — as its
     * national digits: trunk zero and carrier code removed. Null when the
     * workspace is not Brazilian or the number is written internationally.
     */
    private static function national(string $typed, ?string $callingCode): ?string
    {
        if (self::digits((string) $callingCode) !== self::BRAZIL) {
            return null;
        }

        $typed = trim($typed);
        $digits = self::digits($typed);

        if (str_starts_with($typed, '+') || str_starts_with($digits, '00')) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');

            // 0 + carrier (two digits) + area code + number.
            if (in_array(strlen($digits), [12, 13], true)) {
                $digits = substr($digits, 2);
            }

            return $digits;
        }

        // 55 + area code + number, typed without the "+".
        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, self::BRAZIL)) {
            return null;
        }

        return $digits;
    }

    /** Brazilian area codes are two digits, neither of them zero. */
    public static function valid(?string $areaCode): ?string
    {
        return $areaCode !== null && preg_match('/^[1-9]{2}$/', $areaCode) ? $areaCode : null;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
