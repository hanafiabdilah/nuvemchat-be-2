<?php

namespace App\Services\Market;

use App\Models\MarketDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Which market a domain belongs to.
 *
 * For the moments there is no workspace to ask — above all signup, where the
 * domain someone registers on decides, for good, the currency they will be
 * billed in. A domain registered to no market (the platform domain, localhost,
 * the console's placeholder request) belongs to the default market.
 *
 * The domain map is cached forever and flushed by MarketDomain's model events.
 * A domain written with the query builder (tinker, a migration) must call
 * flush() itself.
 */
final class MarketResolver
{
    private const CACHE_KEY = 'markets:domain-map';

    public static function defaultCode(): string
    {
        return strtoupper(trim((string) config('markets.default', 'BR')));
    }

    public static function codeForHost(?string $host): string
    {
        return self::domainMap()[self::normalizeHost($host)] ?? self::defaultCode();
    }

    /** The market of the domain the current request arrived on. */
    public static function codeForRequest(?Request $request = null): string
    {
        $request ??= app()->bound('request') ? app('request') : null;

        return self::codeForHost($request?->getHost());
    }

    /**
     * One spelling per domain: lower-case, no scheme, no port, no trailing dot.
     * "https://APP.Pingly.co.id.:443" and "app.pingly.co.id" are the same place.
     */
    public static function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));

        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }

        $host = (string) preg_replace('/:\d+$/', '', $host);

        return rtrim($host, '.');
    }

    /** @return array<string, string> domain => market code */
    public static function domainMap(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => MarketDomain::query()->pluck('market_code', 'domain')->all(),
        );
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
