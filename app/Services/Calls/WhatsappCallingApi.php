<?php

namespace App\Services\Calls;

use App\Exceptions\UpstreamServiceException;
use App\Models\Connection;
use App\Services\Connection\Meta\GraphApi;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Cloud API Calling endpoints, and nothing else.
 *
 *   POST /{phone-number-id}/calls      pre_accept | accept | reject | terminate
 *   GET|POST /{phone-number-id}/settings   calling on/off
 *
 * Every failure leaves as an UpstreamServiceException worded by UpstreamError:
 * Meta's own sentences ("(#138000) Calling not enabled") go to the log, never
 * to the agent holding a ringing phone.
 */
class WhatsappCallingApi
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    public const PRE_ACCEPT = 'pre_accept';

    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public const TERMINATE = 'terminate';

    /**
     * One action on one call. `pre_accept` and `accept` carry the browser's
     * SDP answer, and Meta refuses an `accept` whose answer differs from the
     * `pre_accept` one — which is why the caller passes the stored answer, not
     * a fresh one.
     *
     * @throws UpstreamServiceException
     */
    public function action(Connection $connection, string $callId, string $action, ?string $sdp = null): void
    {
        [$phoneNumberId, $token] = $this->credentials($connection);

        $payload = [
            'messaging_product' => 'whatsapp',
            'call_id' => $callId,
            'action' => $action,
        ];

        if ($sdp !== null) {
            $payload['session'] = ['sdp_type' => 'answer', 'sdp' => $sdp];
        }

        // Not GraphApi::retry: its backoff sleeps for seconds, and a call that
        // waits that long inside the request has already rung out. One try,
        // and the caller decides what a failure means.
        $response = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post(self::GRAPH_BASE."/{$phoneNumberId}/calls", $payload);

        if (! $response->successful() || $response->json('success') === false) {
            throw $this->failure($response, $connection, "calls.{$action}", ['call_id' => $callId]);
        }
    }

    /**
     * The number's calling settings as Meta reports them.
     *
     * @return array<string, mixed> The `calling` object (status, restrictions…).
     *
     * @throws UpstreamServiceException
     */
    public function settings(Connection $connection): array
    {
        [$phoneNumberId, $token] = $this->credentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->get(self::GRAPH_BASE."/{$phoneNumberId}/settings"));

        if (! $response->successful()) {
            throw $this->failure($response, $connection, 'settings.get');
        }

        $calling = $response->json('calling');

        return is_array($calling) ? $calling : [];
    }

    /**
     * Switch calling on or off for the number. Only `status` is sent: call
     * hours, icon visibility and callback permission are left as Meta holds
     * them, so turning calling on here never undoes a choice made elsewhere.
     *
     * @throws UpstreamServiceException
     */
    public function setEnabled(Connection $connection, bool $enabled): void
    {
        [$phoneNumberId, $token] = $this->credentials($connection);

        $response = GraphApi::retry(fn () => Http::withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->post(self::GRAPH_BASE."/{$phoneNumberId}/settings", [
                'calling' => ['status' => $enabled ? 'ENABLED' : 'DISABLED'],
            ]));

        if (! $response->successful() || $response->json('success') === false) {
            throw $this->failure($response, $connection, 'settings.update', ['enabled' => $enabled]);
        }
    }

    /** @return array{0: string, 1: string} */
    private function credentials(Connection $connection): array
    {
        $credentials = $connection->credentials ?? [];
        $phoneNumberId = $credentials['phone_number_id'] ?? null;
        $token = $credentials['access_token'] ?? null;

        if (! $phoneNumberId || ! $token) {
            throw UpstreamError::exception(
                UpstreamProvider::Meta,
                'access token missing on the connection',
                null,
                422,
                ['connection_id' => $connection->id],
            );
        }

        return [(string) $phoneNumberId, (string) $token];
    }

    /** @param  array<string, mixed>  $context */
    private function failure(Response $response, Connection $connection, string $op, array $context = []): UpstreamServiceException
    {
        $code = $response->json('error.code');

        return UpstreamError::exception(
            UpstreamProvider::Meta,
            (string) ($response->json('error.message') ?? $response->body()),
            $code !== null ? (string) $code : null,
            $response->status() ?: 502,
            array_merge(['connection_id' => $connection->id, 'op' => $op], $context),
            details: UpstreamError::httpDetails($response),
        );
    }
}
