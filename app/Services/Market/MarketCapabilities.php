<?php

namespace App\Services\Market;

use App\Enums\Integration\IntegrationCategory;
use App\Enums\Integration\IntegrationProvider;
use App\Enums\Market\MarketCapability;
use App\Models\Market;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * What a country is allowed to sell and connect — read on every purchase path.
 *
 * The stored map on `markets.capabilities` holds only what an admin decided.
 * A key that is not in it is not "off" and not "on": it falls back to the
 * supplier's own country (MarketCapability::supplierCountry). That is what makes
 * this safe to deploy and safe to extend — Brazil keeps everything without a
 * backfill, a new country starts without the things that cannot exist there,
 * and a capability added next month has an answer everywhere on the day it
 * ships rather than being invisible until somebody ticks 20 boxes.
 *
 * ⚠️ A gate, not a grant: every caller asks this *in addition to* the permission
 * and plan checks it already had.
 */
final class MarketCapabilities
{
    public static function allows(?string $marketCode, string $key): bool
    {
        return self::forMarket($marketCode)[$key] ?? self::defaultFor($marketCode, $key);
    }

    public static function allowsFor(?Tenant $tenant, string $key): bool
    {
        return self::allows($tenant?->market_code, $key);
    }

    public static function allowsProvider(?string $marketCode, IntegrationProvider $provider): bool
    {
        return self::allows($marketCode, MarketCapability::integrationKey($provider));
    }

    /**
     * Every key with its effective answer for this market.
     *
     * @return array<string, bool>
     */
    public static function forMarket(?string $marketCode): array
    {
        $code = self::code($marketCode);
        $stored = self::stored($code);

        $map = [];

        foreach (MarketCapability::keys() as $key) {
            $map[$key] = array_key_exists($key, $stored)
                ? (bool) $stored[$key]
                : self::defaultFor($code, $key);
        }

        return $map;
    }

    /**
     * What this key answers in this country before anyone has decided.
     *
     * Global suppliers are available everywhere; a supplier that exists in one
     * country is offered in that country only.
     */
    public static function defaultFor(?string $marketCode, string $key): bool
    {
        $supplier = MarketCapability::supplierCountry($key);

        return $supplier === null || $supplier === self::code($marketCode);
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultsFor(?string $marketCode): array
    {
        $code = self::code($marketCode);

        return collect(MarketCapability::keys())
            ->mapWithKeys(fn (string $key) => [$key => self::defaultFor($code, $key)])
            ->all();
    }

    /**
     * The integration providers a workspace in this market may connect.
     *
     * @return list<IntegrationProvider>
     */
    public static function providersFor(?string $marketCode, ?IntegrationCategory $category = null): array
    {
        return collect($category ? IntegrationProvider::forCategory($category) : IntegrationProvider::cases())
            ->filter(fn (IntegrationProvider $provider) => self::allowsProvider($marketCode, $provider))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function providerValuesFor(?string $marketCode, ?IntegrationCategory $category = null): array
    {
        return array_map(
            fn (IntegrationProvider $provider) => $provider->value,
            self::providersFor($marketCode, $category),
        );
    }

    /**
     * The vocabulary, for the Back Office form. Never per market: the labels are
     * the same everywhere, only the answers differ.
     *
     * @return list<array{key: string, group: string, label: string, description: string, category: ?string, supplier_country: ?string}>
     */
    public static function catalog(): array
    {
        $products = array_map(fn (MarketCapability $case) => [
            'key' => $case->value,
            'group' => MarketCapability::GROUP_PRODUCTS,
            'label' => $case->label(),
            'description' => $case->description(),
            'category' => null,
            'supplier_country' => MarketCapability::supplierCountry($case->value),
        ], MarketCapability::cases());

        $integrations = array_map(fn (IntegrationProvider $provider) => [
            'key' => MarketCapability::integrationKey($provider),
            'group' => MarketCapability::GROUP_INTEGRATIONS,
            'label' => $provider->label(),
            'description' => $provider->description(),
            'category' => $provider->category()->value,
            'supplier_country' => MarketCapability::supplierCountry(MarketCapability::integrationKey($provider)),
        ], IntegrationProvider::cases());

        return [...$products, ...$integrations];
    }

    /**
     * Keep only keys that exist, and store booleans.
     *
     * Unknown keys are dropped rather than refused: the vocabulary can shrink
     * (a provider is retired), and a stored map naming something gone should not
     * make a market impossible to save.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function sanitize(array $input): array
    {
        return collect($input)
            ->filter(fn ($value, $key) => is_string($key) && MarketCapability::isKey($key))
            ->map(fn ($value) => (bool) $value)
            ->all();
    }

    public static function flush(?string $marketCode = null): void
    {
        if ($marketCode === null) {
            foreach (Market::query()->pluck('code') as $code) {
                Cache::forget(self::cacheKey($code));
            }

            return;
        }

        Cache::forget(self::cacheKey(self::code($marketCode)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stored(string $code): array
    {
        $map = Cache::rememberForever(
            self::cacheKey($code),
            fn () => Market::query()->whereKey($code)->value('capabilities') ?? [],
        );

        return is_array($map) ? $map : [];
    }

    private static function code(?string $marketCode): string
    {
        $code = strtoupper(trim((string) $marketCode));

        return $code !== '' ? $code : MarketResolver::defaultCode();
    }

    private static function cacheKey(string $code): string
    {
        return "market:capabilities:{$code}";
    }
}
