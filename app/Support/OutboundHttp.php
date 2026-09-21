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
