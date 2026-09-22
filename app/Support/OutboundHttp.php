<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * The redirect half of the SSRF guard.
 *
 * Checking the URL a customer typed is necessary and not sufficient: a host
 * that is unquestionably public can answer `302 Location: http://169.254.169.254/`,
 * and the client will follow it without asking anyone. Guzzle follows up to
 * five redirects by default, so a validated URL was a suggestion rather than a
 * constraint.
 *
 * ⚠️ Redirects are guarded rather than switched off. Plenty of legitimate
 * endpoints answer 301 — a bare domain to its www, http to https, an S3 URL to
 * its regional host — and refusing all of them would break working flows to
 * close a hole that re-checking each hop closes just as well.
 *
 * The callback throws, which Guzzle surfaces as a request failure. Every caller
 * already treats a failed request as a failed request, so nothing new has to be
 * handled at the call sites.
 */
final class OutboundHttp
{
    /** Hops allowed before giving up. Guzzle's default is five. */
    private const MAX_REDIRECTS = 3;

    /**
     * Pin a host to the address it was checked at.
     *
     * ⚠️ Closes the gap between checking and connecting. `PublicUrl` resolves
     * the name to decide whether it is public, and Guzzle then resolves it
     * again to open the socket — so a record with a one-second TTL can answer
     * publicly for the check and privately for the request. Nothing about the
     * first lookup constrains the second.
     *
     * Passing CURLOPT_RESOLVE removes the second lookup: curl uses the address
     * we already vetted. Returns the request unchanged when the host is a
     * literal IP (nothing to resolve) or does not resolve at all (the request
     * will simply fail, which is the honest outcome for a name that is down).
     */
    public static function pinHost(PendingRequest $request, string $url): PendingRequest
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $request;
        }

        // A and AAAA both — pinning only the IPv4 answers would leave an
        // AAAA-only host unpinned, which is the case with no second lookup to
        // constrain at all. See PublicUrl::addressesFor().
        $addresses = array_values(array_filter(
            PublicUrl::addressesFor($host),
            static fn (string $ip) => PublicUrl::isPublicIp($ip),
        ));

        if ($addresses === []) {
            return $request;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $request->withOptions([
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $addresses)],
            ],
        ]);
    }

    /**
     * Apply the redirect guard, and optionally a ceiling on how much will be
     * read.
     *
     * @param  int|null  $maxBytes  Refuse a response that announces more than this.
     */
    public static function guard(PendingRequest $request, ?int $maxBytes = null): PendingRequest
    {
        $options = [
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
                'on_redirect' => static function (RequestInterface $from, ResponseInterface $response, UriInterface $to): void {
                    if (! PublicUrl::isFetchable((string) $to)) {
                        throw new RuntimeException('Refused a redirect to a non-public address: '.$to->getHost());
                    }
                },
            ],
        ];

        if ($maxBytes !== null) {
            // Content-Length is the only chance to refuse before the bytes
            // arrive. A server that omits it is still bounded, just later —
            // callers check the size they ended up with.
            $options['on_headers'] = static function (ResponseInterface $response) use ($maxBytes): void {
                $announced = (int) ($response->getHeaderLine('Content-Length') ?: 0);

                if ($announced > $maxBytes) {
                    throw new RuntimeException("Refused a response of {$announced} bytes (limit {$maxBytes}).");
                }
            };
        }

        return $request->withOptions($options);
    }
}
