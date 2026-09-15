<?php

namespace App\Http\Middleware;

use App\Services\Market\MarketResolver;
use App\Support\PlatformUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 for platform-only routes reached through a country domain.
 *
 * Webhooks, OAuth callbacks, the widget API, signed links and the Back Office
 * live on the platform domain alone — the address PLATFORM_URL hands to every
 * system outside the platform. Caddy is the first line: on a country domain
 * these paths never reach PHP. This is the second, for the day the proxy config
 * is edited by hand on the server and a path slips through. 404 rather than 403:
 * on that domain the route does not exist, and whoever probes it should learn
 * nothing more.
 *
 * A deny-list of domains registered to a market, not an allow-list of the
 * platform host. Addresses this platform handed out before (webhooks still
 * registered at Meta on an old domain), the server's own IP and internal
 * self-requests all keep working. The platform host is never blocked, even if
 * someone registers it to a market.
 */
class EnsurePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(self::isCountryDomain($request->getHost()), 404);

        return $next($request);
    }

    public static function isCountryDomain(?string $host): bool
    {
        $host = MarketResolver::normalizeHost($host);

        if ($host === '' || in_array($host, self::platformHosts(), true)) {
            return false;
        }

        return array_key_exists($host, MarketResolver::domainMap());
    }

    /** @return list<string> */
    private static function platformHosts(): array
    {
        return array_values(array_filter([
            MarketResolver::normalizeHost(PlatformUrl::host()),
            MarketResolver::normalizeHost((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]));
    }
}
