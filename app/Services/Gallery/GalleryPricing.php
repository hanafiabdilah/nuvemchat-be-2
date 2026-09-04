<?php

namespace App\Services\Gallery;

use App\Models\Setting;

/**
 * What a rented gigabyte costs, and the bounds on how many can be rented.
 *
 * Same shape as CreditPricing: the `settings` table is the authority and
 * `config/gallery.php` is only the floor under a platform that has never
 * edited it. Read through here rather than `config()` directly — the price
 * quoted on the tenant's screen and the price charged to their balance have to
 * be the same number, and they stop being the same the moment one of the two
 * reads the config while the other reads the override.
 *
 * A percentage markup would make no sense here: unlike an API Way number there
 * is no upstream catalog to mark up. A gigabyte on our disk costs what the
 * platform decides it costs.
 */
class GalleryPricing
{
    public const KEY_PRICE_PER_GB_CENTS = 'gallery.price_per_gb_cents';

    public const KEY_MIN_RENT_GB = 'gallery.min_rent_gb';

    public const KEY_MAX_RENT_GB = 'gallery.max_rent_gb';

    public static function pricePerGbCents(): int
    {
        return self::intSetting(self::KEY_PRICE_PER_GB_CENTS, (int) config('gallery.pricing.price_per_gb_cents', 190), min: 1);
    }

    public static function minRentGb(): int
    {
        return self::intSetting(self::KEY_MIN_RENT_GB, (int) config('gallery.pricing.min_rent_gb', 1), min: 1);
    }

    public static function maxRentGb(): int
    {
        return max(
            self::minRentGb(),
            self::intSetting(self::KEY_MAX_RENT_GB, (int) config('gallery.pricing.max_rent_gb', 500), min: 1),
        );
    }

    /** A full month of $gb gigabytes at today's price. */
    public static function monthlyCents(int $gb): int
    {
        return max(0, $gb) * self::pricePerGbCents();
    }

    /**
     * @return array{price_per_gb_cents: int, min_rent_gb: int, max_rent_gb: int}
     */
    public static function settings(): array
    {
        return [
            'price_per_gb_cents' => self::pricePerGbCents(),
            'min_rent_gb' => self::minRentGb(),
            'max_rent_gb' => self::maxRentGb(),
        ];
    }

    /**
     * Store the commercial block. Only the keys present are written, so a save
     * carrying just the price leaves the bounds alone.
     *
     * @param  array{price_per_gb_cents?: mixed, min_rent_gb?: mixed, max_rent_gb?: mixed}  $values
     */
    public static function store(array $values): void
    {
        foreach ([
            self::KEY_PRICE_PER_GB_CENTS => 'price_per_gb_cents',
            self::KEY_MIN_RENT_GB => 'min_rent_gb',
            self::KEY_MAX_RENT_GB => 'max_rent_gb',
        ] as $key => $field) {
            if (array_key_exists($field, $values) && is_numeric($values[$field])) {
                Setting::set($key, (string) max(1, (int) $values[$field]));
            }
        }
    }

    private static function intSetting(string $key, int $default, int $min): int
    {
        $value = Setting::get($key);

        return is_numeric($value) ? max($min, (int) $value) : max($min, $default);
    }
}
