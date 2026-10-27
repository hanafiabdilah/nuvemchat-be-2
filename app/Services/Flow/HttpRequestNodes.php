<?php

namespace App\Services\Flow;

use App\Support\OutboundHttp;
use App\Support\PublicUrl;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The HTTP Request node's call: build it, guard it, make it, and read the
 * answer into variables.
 *
 * One implementation for the two places a node's request is made — the engine
 * running a flow for a customer, and the builder's test panel running it for
 * the author — because the rules that matter here are not cosmetic. Where the
 * request may go (PublicUrl, the redirect guard), how long it may hold a worker
 * and how a response becomes `{{variables}}` are exactly the things a test has
 * to show truthfully; a second copy of them would be a test of something else.
 *
 * Interpolation is the caller's: the engine reads {{tokens}} from a flow
 * state, the test panel from the variables on its screen.
 */
class HttpRequestNodes
{
    /**
     * Make the node's request.
     *
     * Never throws. Every way the call can fail — no URL, an address that is
     * not public, a timeout, a refused connection — comes back as `branch:
     * error`, the branch the author already drew for "this call did not work".
     *
     * `mapped` is only filled when a response arrived (any status, as the
     * engine always did); a call that never got an answer maps nothing.
     *
     * @param  array<string, mixed>  $data  the node's data
     * @param  Closure(string): string  $interpolate
     * @param  (Closure(string, string): void)|null  $beforeSend  told the method and URL just before the call
     * @param  array<string, mixed>  $logContext
     * @return array{
     *     branch: 'success'|'error',
     *     method: string,
     *     url: string,
     *     status: int|null,
     *     body: string|null,
     *     mapped: array<string, mixed>,
     *     error: string|null,
     * }
     */
    public static function perform(array $data, Closure $interpolate, ?Closure $beforeSend = null, array $logContext = []): array
    {
        $method = strtoupper((string) ($data['method'] ?? 'GET'));
        $url = trim($interpolate((string) ($data['url'] ?? '')));

        $result = [
            'branch' => 'error',
            'method' => $method,
            'url' => $url,
            'status' => null,
            'body' => null,
            'mapped' => [],
            'error' => null,
        ];

        if ($url === '') {
            Log::warning('HttpRequestNodes: no URL, taking error branch', $logContext);

            return ['error' => 'missing_url'] + $result;
        }

        // ⚠️ Where the request may go, checked before it is made. This node
        // reaches out on behalf of whoever wrote the flow, and the URL can be
        // assembled from {{variables}} filled by the customer's own messages —
        // so without this it was read access to the platform's own network
        // (cloud metadata, the database, Reverb, the queue) with the response
        // mapped into a variable and sent straight back to the customer.
        if (! PublicUrl::isFetchable($url)) {
            Log::warning('HttpRequestNodes: refused a non-public address', $logContext + [
                // Host only. A flow author's query string carries their tokens
                // and their customer's identifiers.
                'host' => parse_url($url, PHP_URL_HOST),
            ]);

            return ['error' => 'address_refused'] + $result;
        }

        try {
            // Build interpolated headers from the list of { key, value } rows.
            $headers = [];
            foreach ((array) ($data['headers'] ?? []) as $header) {
                $key = trim((string) ($header['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $headers[$key] = $interpolate((string) ($header['value'] ?? ''));
            }

            // ⚠️ Clamped at RUNTIME, not only at save. FlowBlueprint validates
            // 1–120, but rows written before that rule existed carry whatever
            // was in them, and this number is how long a worker — a PHP-FPM
            // one while flows still run inline — is held by an endpoint that
            // never answers.
            $timeout = (int) ($data['timeout'] ?? 15);
            if ($timeout <= 0) {
                $timeout = 15;
            }
            $timeout = min($timeout, (int) config('flow.http_max_timeout', 120));

            // The URL was checked above; the redirect guard is what keeps that
            // check meaningful, since a public host may answer 302 with a
            // private one and the client would follow it.
            $request = OutboundHttp::guard(
                OutboundHttp::pinHost(
                    Http::withHeaders($headers)
                        ->timeout($timeout)
                        ->connectTimeout(min($timeout, 10)),
                    $url,
                ),
            );

            // Prepare the body for write verbs. If it parses as JSON we send it
            // as a JSON payload; otherwise it goes out as a raw string.
            $jsonBody = null;

            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $rawBody = $interpolate((string) ($data['body'] ?? ''));

                if (trim($rawBody) !== '') {
                    $decoded = json_decode($rawBody, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $jsonBody = $decoded;
                    } else {
                        $request = $request->withBody($rawBody, $headers['Content-Type'] ?? 'text/plain');
                    }
                }
            }

            if ($beforeSend) {
                $beforeSend($method, $url);
            }

            $response = match ($method) {
                'POST' => $jsonBody !== null ? $request->post($url, $jsonBody) : $request->post($url),
                'PUT' => $jsonBody !== null ? $request->put($url, $jsonBody) : $request->put($url),
                'PATCH' => $jsonBody !== null ? $request->patch($url, $jsonBody) : $request->patch($url),
                'DELETE' => $jsonBody !== null ? $request->delete($url, $jsonBody) : $request->delete($url),
                default => $request->get($url),
            };

            $json = null;
            try {
                $json = $response->json();
            } catch (\Throwable $e) {
                $json = null; // response wasn't JSON — only http_status/raw_body usable
            }

            $mapped = [];

            foreach ((array) ($data['response_mappings'] ?? []) as $mapping) {
                $variable = trim((string) ($mapping['variable'] ?? ''));
                if ($variable === '') {
                    continue;
                }

                $path = trim((string) ($mapping['path'] ?? ''));

                $value = match ($path) {
                    'http_status' => $response->status(),
                    'raw_body', '' => $response->body(),
                    default => is_array($json) ? data_get($json, $path) : null,
                };

                // Flatten arrays/objects so single-depth Condition lookups still work.
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                $mapped[$variable] = $value;
            }

            return [
                'branch' => $response->successful() ? 'success' : 'error',
                'status' => $response->status(),
                'body' => $response->body(),
                'mapped' => $mapped,
            ] + $result;
        } catch (\Throwable $th) {
            Log::error('HttpRequestNodes: request failed, taking error branch', $logContext + [
                'error' => $th->getMessage(),
            ]);

            return ['error' => 'request_failed'] + $result;
        }
    }
}
