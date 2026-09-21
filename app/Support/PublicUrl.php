<?php

namespace App\Support;

/**
 * Where this server is willing to send a request that somebody else chose.
 *
 * Three places take a URL from a customer and fetch it: outbound webhooks, the
 * flow engine's `http_request` node, and `media_url` on every send endpoint.
 * Only the first ever checked. The other two would happily fetch
 * `http://169.254.169.254/v1.json` (the instance metadata service),
 * `http://127.0.0.1:6379`, or a Docker service name like `app:9000` — and both
 * hand the response back to the caller, so it was read access to the platform's
 * own network with the answer delivered to the attacker's chat.
 *
 * The rule itself was already written and already correct, in
 * App\Services\Webhooks\WebhookUrl. This is that rule, moved somewhere all
 * three can reach it; WebhookUrl keeps its own wording and delegates here.
 *
 * ⚠️ Host checks alone are not enough, because a name can be pointed somewhere
 * else after it is checked, and because a public host can answer 302 to a
 * private one. Callers pair this with a redirect guard — see
 * App\Support\OutboundHttp.
 */
final class PublicUrl
{
    /**
     * Whether this is an http(s) URL naming a host on the public internet.
     *
     * Rejects, in order: anything that is not http or https (which is what
     * keeps `file://`, `gopher://` and `dict://` out), names that can only mean
     * something inside a network — `localhost`, `.local`, `.internal`, and any
     * bare name with no dot in it, which is how Docker service names look — and
     * literal addresses in private or reserved ranges.
     */
    public static function isPublic(string $url): bool
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! $parts || $host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return false;
        }

        $internalName = $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || (! str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP));

        if ($internalName) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && ! self::isPublicIp($host)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the host currently resolves to something not on the public
     * internet.
     *
     * ⚠️ Unresolvable is NOT private. A name that is down simply fails, and
     * treating a DNS hiccup as an attack would turn every outage at a
     * customer's endpoint into a refusal they cannot diagnose.
     */
    public static function resolvesToPrivate(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! self::isPublicIp($host);
        }

        foreach (gethostbynamel($host) ?: [] as $address) {
            if (! self::isPublicIp($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this server may fetch the URL at all.
     *
     * Public addresses, plus our own — the download-and-reupload fallback on
     * the send path is handed URLs this platform minted itself
     * (`/storage/…`, `/gallery/…`), and in production those are already public
     * so the exemption changes nothing there. What it buys is development and
     * the test suite, where APP_URL is `localhost` and every one of our own
     * links would otherwise be refused by the rule above.
     *
     * ⚠️ Compared as a whole origin — scheme, host AND port — not by host
     * alone. Matching on host would make `http://localhost:6379` "our own
     * address" on any machine where APP_URL is localhost, which is the exact
     * request this is here to stop.
     *
     * ⚠️ The own-origin branch short-circuits the DNS check too. Our own host
     * resolving to 127.0.0.1 is the normal state of affairs in development and
     * in the test suite, and refusing it there would mean every link this
     * platform mints for itself — a Pix QR, a gallery file, an invoice PDF —
     * is unfetchable on a developer's machine.
     */
    public static function isFetchable(string $url): bool
    {
        if (self::isOwnOrigin($url)) {
            return true;
        }

        return self::isPublic($url) && ! self::resolvesToPrivate($url);
    }

    /** Whether the URL sits on an origin this platform serves itself. */
    public static function isOwnOrigin(string $url): bool
    {
        $origin = self::originOf($url);

        if ($origin === null) {
            return false;
        }

        foreach (self::trustedOrigins() as $trusted) {
            if ($trusted !== null && hash_equals($trusted, $origin)) {
                return true;
            }
        }

        return false;
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return list<string|null> */
    private static function trustedOrigins(): array
    {
        return array_map(self::originOf(...), array_filter([
            PlatformUrl::root(),
            (string) config('app.url'),
            (string) config('app.frontend_url'),
            // Wherever media actually lives once it has left the local disk.
            (string) config('filesystems.disks.media_public.url'),
            (string) config('filesystems.disks.public.url'),
        ]));
    }

    /** `https://host:port`, lowercased, with the default port left implicit. */
    private static function originOf(?string $url): ?string
    {
        $parts = parse_url(trim((string) $url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.':'.((int) ($port ?: $default));
    }
}
