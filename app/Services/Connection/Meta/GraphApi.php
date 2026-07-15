<?php

namespace App\Services\Connection\Meta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Wraps a Meta Graph API call with retry + exponential backoff on rate limits
 * and transient server errors.
 *
 * Graph enforces rate limits with HTTP 429 and with `error.code` values
 * 4 / 17 / 32 / 613 / 80007 (app, user, page, and messaging throttles). Rather
 * than failing the send/probe outright, we back off and retry a few times,
 * honoring a `Retry-After` header when Meta provides one.
 *
 * Usage:
 *   $response = GraphApi::retry(fn () =>
 *       Http::withToken($token)->post($url, $payload)
 *   );
 */
class GraphApi
{
    public const MAX_ATTEMPTS = 3;

    /** Meta error.code values that indicate throttling. */
    private const RATE_LIMIT_CODES = [4, 17, 32, 613, 80007];

    /**
     * @param  callable():Response  $send  Performs the HTTP call and returns the Response.
     */
    public static function retry(callable $send, int $maxAttempts = self::MAX_ATTEMPTS): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $response = $send();

            // Not throttled (covers success AND non-throttle errors), or out of
            // attempts → return as-is. Note Meta can return HTTP 200 with an
            // error.code throttle envelope, so the throttle check must come
            // before any success short-circuit.
            if (!self::isRateLimited($response) || $attempt >= $maxAttempts) {
                return $response;
            }

            $delayMs = self::backoffMs($response, $attempt);

            Log::warning('Graph API rate-limited; backing off before retry', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'status' => $response->status(),
                'error_code' => $response->json('error.code'),
                'delay_ms' => $delayMs,
            ]);

            Sleep::for($delayMs)->milliseconds();
        }
    }

    /**
     * Whether a response represents a retryable throttle / transient error.
     */
    public static function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429
            || in_array($response->status(), [500, 502, 503], true)
        ) {
            return true;
        }

        $code = (int) ($response->json('error.code') ?? 0);

        return in_array($code, self::RATE_LIMIT_CODES, true);
    }

    /**
     * Backoff delay in milliseconds. Honors a numeric `Retry-After` header
     * (seconds), otherwise grows exponentially: 500ms, 1000ms, 2000ms, ...
     * capped so a synchronous request never stalls too long.
     */
    private static function backoffMs(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');
        if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
            return (int) min((int) $retryAfter * 1000, 10000);
        }

        return (int) min(500 * (2 ** ($attempt - 1)), 8000);
    }
}
