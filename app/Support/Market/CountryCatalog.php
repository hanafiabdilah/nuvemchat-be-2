<?php

namespace App\Support\Market;

use DateTimeZone;

/**
 * The countries a market can be opened in, and what the platform already knows
 * about each: its currency, its calling code and its timezones.
 *
 * So opening a country is picking it from a list, not typing "IDR", "62" and
 * "Asia/Jakarta" from memory — a typo there becomes the currency a workspace is
 * billed in for good.
 *
 * Currency and calling code come from resources/data/countries.json, generated
 * once from ICU (currency per region) and libphonenumber (calling code), so the
 * server needs neither the intl extension nor a phone library to read it.
 * Timezones are read live from PHP's own tz database, which ships with PHP and
 * is updated with it. Territories with no timezone of their own (Ascension,
 * Tristan da Cunha, Kosovo) are left out: a market needs a default timezone.
 */
final class CountryCatalog
{
    /** @var array<string, array{currency: string, calling_code: string}>|null */
    private static ?array $countries = null;

    /** @return array<string, array{currency: string, calling_code: string}> ISO 3166-1 alpha-2 => facts */
    public static function all(): array
    {
        return self::$countries ??= json_decode(
            (string) file_get_contents(resource_path('data/countries.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array{currency: string, calling_code: string}|null */
    public static function find(?string $code): ?array
    {
        return self::all()[strtoupper(trim((string) $code))] ?? null;
    }

    /** @return list<string> Every currency some country uses, sorted. */
    public static function currencies(): array
    {
        $currencies = array_values(array_unique(array_column(self::all(), 'currency')));
        sort($currencies);

        return $currencies;
    }

    /** @return list<string> The country's timezones; empty for a code not in the catalog. */
    public static function timezones(string $code): array
    {
        $code = strtoupper(trim($code));

        // listIdentifiers() throws on a malformed country code, so only ask
        // about codes the catalog vouches for.
        if (! isset(self::all()[$code])) {
            return [];
        }

        return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);
    }
}
