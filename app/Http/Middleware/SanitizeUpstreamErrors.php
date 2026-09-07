<?php

namespace App\Http\Middleware;

use App\Support\Errors\UpstreamError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Last line of defence: no upstream sentence leaves the tenant API.
 *
 * The real fix is at the call sites — every one of them now goes through
 * App\Support\Errors\UpstreamError, which translates and logs. This exists
 * because that guarantee has to survive the next feature: a new integration, a
 * `catch` written in a hurry, a `$e->getMessage()` copied from the file next
 * door. Without a net, the leak is invisible until a customer screenshots
 * "provider must be one of the following values" and asks what it means.
 *
 * Deliberately narrow, because a false positive rewrites copy we wrote:
 *
 *  - error responses only (4xx/5xx). A 200 carrying a message field is data.
 *  - the `message` / `error` fields and validation `errors`, nothing else.
 *  - a short list of high-signal fingerprints (UpstreamError::looksExternal),
 *    not a general "looks technical" heuristic.
 *
 * ⚠️ Back Office (`api/admin/*`) is exempt on purpose. Platform operators are
 * the people who fix integrations, and the upstream's exact words are the only
 * thing that tells them which one broke — hiding it there would remove the
 * single surface where that text is worth something.
 */
class SanitizeUpstreamErrors
{
    private const REPLACEMENT = 'Não foi possível concluir a operação agora. Tente novamente em instantes.';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        if ($request->is('api/admin/*')) {
            return $response;
        }

        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        $leaked = [];
        $clean = $this->scrub($payload, $leaked);

        if ($leaked === []) {
            return $response;
        }

        // Warn rather than fail: the customer gets the safe answer either way,
        // and this line is how the un-translated call site gets found.
        Log::warning('SanitizeUpstreamErrors: an upstream message reached a tenant response', [
            'route' => $request->path(),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'tenant_id' => $request->user()?->tenant_id,
            'leaked' => $leaked,
        ]);

        $response->setData($clean);

        return $response;
    }

    /**
     * Replace upstream text in the fields a client renders, leaving the rest of
     * the payload untouched.
     *
     * @param  array<mixed>  $payload
     * @param  list<string>  $leaked  Collects what was replaced, for the log.
     * @return array<mixed>
     */
    private function scrub(array $payload, array &$leaked): array
    {
        foreach (['message', 'error'] as $key) {
            if (is_string($payload[$key] ?? null) && UpstreamError::looksExternal($payload[$key])) {
                $leaked[] = $payload[$key];
                $payload[$key] = self::REPLACEMENT;
            }
        }

        // Laravel's validation shape: errors => field => [messages].
        if (is_array($payload['errors'] ?? null)) {
            array_walk_recursive($payload['errors'], function (&$value) use (&$leaked) {
                if (is_string($value) && UpstreamError::looksExternal($value)) {
                    $leaked[] = $value;
                    $value = self::REPLACEMENT;
                }
            });
        }

        return $payload;
    }
}
