<?php

namespace App\Services\Integrations\Concerns;

use App\Models\Integration;
use App\Support\Errors\UpstreamError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * One way every integration driver talks to its provider, and one way its
 * failures come back: already translated, with a reference.
 *
 * The provider's own words never leave this trait except into the log line
 * UpstreamError writes. A driver that returned `$response->body()` to a
 * controller would put "Invalid access token for app 123 (fbtrace_id …)" in a
 * toast, which is the leak UpstreamError exists to close.
 */
trait CallsProvider
{
    abstract protected function integration(): Integration;

    abstract protected function request(): PendingRequest;

    /**
     * The provider's error sentence and code, read out of a failed response.
     *
     * @return array{0: string|null, 1: string|null}
     */
    abstract protected function errorFrom(Response $response): array;

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    protected function call(callable $call, string $action): array
    {
        $integration = $this->integration();
        $context = [
            'integration_id' => $integration->id,
            'tenant_id' => $integration->tenant_id,
            'action' => $action,
        ];

        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            throw UpstreamError::exception(
                $integration->provider->upstream(),
                $e->getMessage(),
                status: 504,
                context: $context,
                previous: $e,
            );
        }

        if ($response->failed()) {
            [$message, $code] = $this->errorFrom($response);
            $status = $response->status();

            // The status wins over the vendor's own code for the three
            // outcomes every vendor agrees on, so the dictionaries can match
            // "the key was refused" without learning four dialects of it.
            $code = in_array($status, [401, 403, 429], true)
                ? (string) $status
                : ($code ?? (string) $status);

            throw UpstreamError::exception(
                $integration->provider->upstream(),
                $message ?: $response->body(),
                $code,
                $status,
                $context,
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
