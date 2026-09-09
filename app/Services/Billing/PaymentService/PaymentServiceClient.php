<?php

namespace App\Services\Billing\PaymentService;

use App\Exceptions\UserFacingException;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the group's payment service.
 *
 * Deliberately thin. Everything gateway-shaped — which provider, how a Pix QR
 * is fetched, what a decline code means — lives on the other side; what crosses
 * here is money in minor units, an order reference, and a status.
 *
 * Two protections against a double charge, and this class carries both. The
 * `Idempotency-Key` header collapses a retry of the *same request*. The pair of
 * product and `order_reference` is unique in the service's database, so one
 * order can never become two payments even across different keys, different
 * workers, or an operator retrying by hand days later. Callers must build the
 * reference from the thing being paid for — see BillingService::orderReference().
 */
class PaymentServiceClient
{
    public function isConfigured(): bool
    {
        return PaymentServiceConfig::isConfigured();
    }

    // --- Payments -----------------------------------------------------------

    /**
     * Create a payment. Read `status` on the way out — a 201 does not mean the
     * money arrived, and a 200 means this order already existed and the
     * original is being handed back rather than a second one created.
     *
     * ⚠️ `status: 'unknown'` is not a failure. It means the gateway did not
     * answer, so nobody knows whether the charge exists on their side; creating
     * a second payment for the same order is exactly how a customer is billed
     * twice. It resolves on its own and the notification follows.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>  The whole envelope: data, client_token, notice.
     */
    public function createPayment(array $payload, string $idempotencyKey): array
    {
        $this->assertReferenceIsPortable($payload['order_reference'] ?? null);

        return $this->decode(
            $this->request(timeout: 60)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->url('/payments'), $this->withProvider($payload)),
        );
    }

    /** @return array<string, mixed> The payment object. */
    public function getPayment(string $id): array
    {
        return $this->unwrap($this->request()->get($this->url("/payments/{$id}")));
    }

    /**
     * Reconciliation, and the fallback when a webhook was never delivered —
     * which is why the service is allowed to give up on a delivery at all.
     *
     * @param  array<string, mixed>  $query
     */
    public function listPayments(array $query = []): array
    {
        return $this->decode($this->request()->get($this->url('/payments'), $query));
    }

    public function refund(string $id, array $payload, string $idempotencyKey): array
    {
        return $this->decode(
            $this->request(timeout: 60)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->url("/payments/{$id}/refunds"), $payload),
        );
    }

    // --- Catalogue ----------------------------------------------------------

    /**
     * What can be charged right now, computed from live provider accounts.
     *
     * Worth calling on every checkout render and caching for no more than a
     * minute — the point of the endpoint is that it changes. Carries
     * `merchant_initiated_cards`, which is what decides whether this platform
     * may promise a card subscription at all.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethods(?string $currency = 'BRL'): array
    {
        $response = $this->decode(
            $this->request()->get($this->url('/payment-methods'), array_filter(['currency' => $currency])),
        );

        return $response['data'] ?? [];
    }

    // --- Customers & instruments -------------------------------------------

    /**
     * Create the customer under our own reference, or update the one already
     * there. Idempotent by that reference rather than by a key: a retried
     * signup should converge on one person, not two.
     */
    public function saveCustomer(array $payload): array
    {
        return $this->unwrap($this->request()->post($this->url('/customers'), $payload));
    }

    /**
     * Everything the browser needs to tokenise a card: a public key and the
     * name of the SDK to load.
     *
     * That name is what keeps switching gateway from being a front-end deploy,
     * and the response also says which provider was routed — which the first
     * charge has to reuse, since the token it mints belongs to that gateway
     * alone.
     */
    public function instrumentSession(array $payload): array
    {
        return $this->decode($this->request()->post($this->url('/instrument-sessions'), $payload));
    }

    /** Is this still chargeable? Established without charging it. */
    public function verifyInstrument(string $id): array
    {
        return $this->decode($this->request()->post($this->url("/instruments/{$id}/verify")));
    }

    public function deleteInstrument(string $id): array
    {
        return $this->decode($this->request()->delete($this->url("/instruments/{$id}")));
    }

    // --- Plumbing -----------------------------------------------------------

    /**
     * Attach the operator's provider choice — but only where it can be honoured.
     *
     * A card token is minted by one gateway's SDK and is meaningless to
     * another, so the first card charge must run against the provider the
     * instrument session was routed to (callers pass it explicitly). A stored
     * instrument carries its own gateway, and the service ignores `provider`
     * beside `instrument_id` outright. That leaves Pix as the only place the
     * setting can actually decide anything — so the rule lives here once,
     * rather than in every call site that would have to remember it.
     */
    protected function withProvider(array $payload): array
    {
        if (array_key_exists('provider', $payload) || isset($payload['instrument_id']) || isset($payload['card_token'])) {
            return $payload;
        }

        $provider = PaymentServiceConfig::provider();

        return $provider === null ? $payload : [...$payload, 'provider' => $provider];
    }

    /**
     * The character set every gateway behind the service accepts.
     *
     * dLocal Go is the strict one: it forwards `order_reference` as the
     * payment's `order_id` and refuses anything outside this, under its own
     * name for the field (`invoiceId`). Others are looser, but nothing is
     * bought by using the wider set — so this is checked for all of them, and
     * a reference is portable by construction rather than by which provider
     * happened to take the charge.
     */
    protected const PORTABLE_REFERENCE = '/^[A-Za-z0-9\-_]+$/';

    /**
     * Refuse a reference no gateway would keep, before one is asked to.
     *
     * ⚠️ Ours, never the customer's: they cannot influence this string, so the
     * message says so and the detail goes to the log. Throwing is the right
     * answer rather than sanitising here — silently rewriting the idempotency
     * anchor at the last moment would give one period two references, and the
     * exactly-once guarantee only holds while a period maps to exactly one.
     */
    protected function assertReferenceIsPortable(?string $reference): void
    {
        if ($reference !== null && preg_match(self::PORTABLE_REFERENCE, $reference) === 1) {
            return;
        }

        Log::error('Refusing to send an order reference no gateway will accept', [
            'order_reference' => $reference,
            'expected' => self::PORTABLE_REFERENCE,
        ]);

        throw new UserFacingException(
            'Não foi possível iniciar esta cobrança. Já estamos verificando — tente novamente em instantes.',
            502,
            'payment_reference_invalid',
        );
    }

    protected function request(int $timeout = 30): PendingRequest
    {
        $key = PaymentServiceConfig::apiKey();

        if ($key === null) {
            // Ours to fix, and phrased as such: a customer told "payment
            // service API key is not configured" has learned the name of a
            // setting they cannot see, about a system they do not have.
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'Payment service API key is not configured.',
                upstreamCode: 'payment_service_unconfigured',
                status: 503,
            );
        }

        return Http::withToken($key)
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout($timeout)
            // Only connection failures, and only reads/idempotent writes: every
            // write here carries an Idempotency-Key, so a retry after a dropped
            // connection returns the original payment rather than a second one.
            ->retry(2, 1500, fn ($e) => $e instanceof HttpConnectionException, throw: false);
    }

    protected function url(string $path): string
    {
        return PaymentServiceConfig::baseUrl().$path;
    }

    /**
     * Decode, or translate the failure.
     *
     * The service answers errors as `{code, message}` where the message names a
     * provider and quotes its reason. Both are exactly what must not reach a
     * business owner, so this is the one place they are read: the code steers
     * the dictionary, the sentence goes to the log with a reference, and what
     * leaves is our own copy.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $body = $response->json();

        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            is_array($body) ? ($body['message'] ?? $body['error'] ?? $response->body()) : $response->body(),
            upstreamCode: is_array($body) ? ($body['code'] ?? null) : null,
            status: $response->status(),
            context: ['payment_service_status' => $response->status()],
        );
    }

    /** @return array<string, mixed> */
    protected function unwrap(Response $response): array
    {
        return $this->decode($response)['data'] ?? [];
    }
}
