<?php

namespace App\Support;

use Illuminate\Routing\UrlGenerator;

/**
 * The one address this platform hands to systems outside it.
 *
 * Laravel builds absolute URLs from the host of the request being served. With
 * a single domain that is invisible; with one domain per country it decides
 * where Telegram, API Way, OpenPix, Asaas, Stripe and Mercado Pago send their
 * webhooks — whichever domain the owner happened to be on when they connected.
 * Those addresses are stored in systems we don't control, and a country domain
 * can lapse or be replaced. When it does, every webhook registered on it stops
 * silently, and nothing on any screen says why messages stopped arriving.
 *
 * So the root is pinned once, at boot, for everything the URL generator makes:
 * route(), signed links (gallery files, Pix QR codes, invoice documents — whose
 * signatures include the host, so a link signed on one domain is rejected on
 * another) and local-disk media links. Pinning it here instead of at each call
 * site is the point: the next integration inherits it without anyone
 * remembering to.
 *
 * Empty means "follow the request", which is exactly the behaviour before this
 * existed — safe to deploy before anyone sets it.
 */
final class PlatformUrl
{
    /** Whether anything was written in PLATFORM_URL, usable or not. */
    public static function isConfigured(): bool
    {
        return trim((string) config('app.platform_url')) !== '';
    }

    /**
     * Scheme, host and optional port — or null when unset or unusable.
     *
     * A path is refused rather than kept: routes are registered at the root,
     * so "https://host/api" would prefix every generated URL with a segment no
     * route answers to.
     */
    public static function root(): ?string
    {
        $value = rtrim(trim((string) config('app.platform_url')), '/');

        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || ($parts['path'] ?? '') !== ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** The host alone, for comparisons and display. */
    public static function host(): ?string
    {
        $root = self::root();

        return $root === null ? null : (string) parse_url($root, PHP_URL_HOST);
    }

    /**
     * Pin the generator to the platform root. Returns false when there is
     * nothing usable to pin, leaving the generator following the request.
     */
    public static function apply(UrlGenerator $url): bool
    {
        $root = self::root();

        if ($root === null) {
            return false;
        }

        $url->useOrigin($root);

        // The root's scheme alone is not enough: the generator swaps it for the
        // request's, so a request that reached PHP over plain HTTP would still
        // hand out http:// addresses that providers refuse.
        $url->forceScheme((string) parse_url($root, PHP_URL_SCHEME));

        return true;
    }
}
