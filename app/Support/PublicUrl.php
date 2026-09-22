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

        foreach (self::addressesFor($host) as $address) {
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

    /**
     * Ranges PHP's FILTER_FLAG_NO_RES_RANGE does not cover.
     *
     * ⚠️ `filter_var(..., NO_PRIV_RANGE | NO_RES_RANGE)` is the obvious way to
     * ask this question and it is not sufficient. It knows the ranges everyone
     * remembers — 10/8, 127/8, 169.254/16 — and misses shared address space,
     * the IETF and benchmarking blocks, and multicast, all of which route to
     * real infrastructure inside a provider's network.
     *
     * @var list<string>
     */
    private const RESERVED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',
        '100.64.0.0/10',      // carrier NAT — routes inside the provider
        '127.0.0.0/8',
        '169.254.0.0/16',     // link-local, and the cloud metadata service
        '172.16.0.0/12',
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, incl. 255.255.255.255
    ];

    /** @var list<string> */
    private const RESERVED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '64:ff9b:1::/48',     // NAT64, local use
        '100::/64',           // discard-only
        '2001::/32',          // Teredo — dead, and it tunnels to an IPv4 host
        '2001:2::/48',        // benchmarking
        '2001:20::/28',       // ORCHIDv2
        '2001:db8::/32',      // documentation
        '3fff::/20',          // documentation
        '5f00::/16',          // SRv6
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * Whether an address literal is on the public internet.
     *
     * ⚠️⚠️ The version of this that only called `filter_var` was bypassable,
     * and not subtly: `http://[::ffff:169.254.169.254]/` passed it. An
     * IPv4-mapped IPv6 address is 127.0.0.1 or the metadata service written in
     * a notation the flags above do not recognise, and the socket layer
     * connects to exactly the IPv4 host it names. 6to4 (`2002::/16`) and the
     * NAT64 prefix carry an IPv4 address the same way. So an address that
     * wraps an IPv4 one is unwrapped and judged as what it will actually
     * reach, rather than as the notation it arrived in.
     *
     * This is the same class of gap as CVE-2026-48736 against Symfony's
     * IpUtils — worth knowing, because that is where anyone reaching for a
     * library instead would have landed.
     */
    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton(trim($ip, '[]'));

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            $embedded = self::embeddedIpv4($packed);

            if ($embedded !== null) {
                return self::isPublicIp($embedded);
            }

            return ! self::inAnyRange($packed, self::RESERVED_V6);
        }

        return ! self::inAnyRange($packed, self::RESERVED_V4);
    }

    /**
     * Every address a host currently answers with, A records and AAAA.
     *
     * ⚠️ `gethostbynamel()` alone returns IPv4 only, so a name whose only
     * record is AAAA resolved to nothing, "nothing" was read as "not private",
     * and the request went out over IPv6 to whatever it pointed at. Anyone
     * publishing an AAAA record for `::1` had a way through.
     *
     * @return list<string>
     */
    public static function addressesFor(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        try {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        } catch (\Throwable $e) {
            // A resolver that cannot answer AAAA is not evidence of anything;
            // the A records already gathered still stand.
        }

        return array_values(array_unique($addresses));
    }

    /** The IPv4 address an IPv6 one wraps, if it wraps one. */
    private static function embeddedIpv4(string $packed): ?string
    {
        $zeroes = str_repeat("\0", 10);

        // ::ffff:a.b.c.d (IPv4-mapped) and ::a.b.c.d (IPv4-compatible).
        // `::` and `::1` land here too and come back as 0.0.0.0 and 0.0.0.1,
        // both of which the IPv4 list already refuses.
        if (substr($packed, 0, 10) === $zeroes) {
            $marker = substr($packed, 10, 2);

            if ($marker === "\xff\xff" || $marker === "\0\0") {
                return inet_ntop(substr($packed, 12, 4)) ?: null;
            }
        }

        // 2002:AABB:CCDD::/16 — 6to4 carries the IPv4 in the next 32 bits.
        if (substr($packed, 0, 2) === "\x20\x02") {
            return inet_ntop(substr($packed, 2, 4)) ?: null;
        }

        // 64:ff9b::/96 — the well-known NAT64 prefix, IPv4 in the last 32 bits.
        if (substr($packed, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
            return inet_ntop(substr($packed, 12, 4)) ?: null;
        }

        return null;
    }

    /** @param list<string> $ranges CIDR blocks of the same family as $packed */
    private static function inAnyRange(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $networkPacked = @inet_pton($network);

            if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }

            if (self::sharesPrefix($packed, $networkPacked, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    /** Whether two packed addresses agree on their first $bits bits. */
    private static function sharesPrefix(string $a, string $b, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);

        if ($wholeBytes > 0 && substr($a, 0, $wholeBytes) !== substr($b, 0, $wholeBytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($a[$wholeBytes]) & $mask) === (ord($b[$wholeBytes]) & $mask);
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
