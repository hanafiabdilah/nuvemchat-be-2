<?php

namespace App\Services\Integrations\Payments;

use App\Enums\Flow\FlowPaymentStatus;
use App\Models\FlowPayment;
use App\Models\Integration;
use App\Services\Integrations\Concerns\CallsProvider;
use App\Services\Integrations\ManagesWebhooks;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Stripe on the workspace's own account: a Checkout Session link.
 *
 * Checkout, and only Checkout, on purpose. It is one hosted page that takes
 * card — and Pix, when the Stripe account has it enabled — behind a single
 * link, which is exactly what a chat can hand over. Stripe can also produce a
 * Pix copy-and-paste code without the page (a PaymentIntent confirmed on the
 * server), but its own guide only documents that confirmation from a browser,
 * and a Pix that silently fails to render in the chat is worse than a link that
 * works.
 *
 * Form-encoded bodies, amounts in centavos, and our reference as the
 * `Idempotency-Key`, so a retried create returns the session it already made.
 * A session must live between 30 minutes and 24 hours; the node's deadline is
 * clamped into that window, and the session is expired at the deadline
 * (CancelsCharges) so the link stops working when the flow stops waiting.
 */
class StripeGateway implements PaymentGateway, ManagesWebhooks, CancelsCharges
{
    use CallsProvider;

    public const BASE_URL = 'https://api.stripe.com';

    /** Stripe refuses a Checkout Session that expires sooner than this… */
    public const MIN_MINUTES = 30;

    /** …or later than this. */
    public const MAX_MINUTES = 1440;

    public const WEBHOOK_EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
    ];

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) $this->integration->credential('secret_key'))
            ->acceptJson()
            ->asForm()
            ->timeout(25)
            ->connectTimeout(8);
    }

    protected function errorFrom(Response $response): array
    {
        $message = $response->json('error.message');
        $code = $response->json('error.code') ?? $response->json('error.type');

        return [is_string($message) ? $message : null, is_string($code) ? $code : null];
    }

    private function isTest(): bool
    {
        return str_contains((string) $this->integration->credential('secret_key'), '_test_');
    }

    public function verify(): array
    {
        $this->call(fn (PendingRequest $http) => $http->get('/v1/balance'), 'verify');

        $account = [];

        try {
            $me = $this->call(fn (PendingRequest $http) => $http->get('/v1/account'), 'account');
            $account = [
                'account_id' => $me['id'] ?? null,
                'account_name' => $me['settings']['dashboard']['display_name'] ?? $me['business_profile']['name'] ?? null,
                'account_email' => $me['email'] ?? null,
                // Pix only exists for BRL; a non-Brazilian account shows it
                // on the page only as a cross-border method, if at all.
                'country' => $me['country'] ?? null,
            ];
        } catch (\Throwable) {
            // A restricted key without account reads still creates sessions.
        }

        return array_filter(array_merge($account, [
            'environment' => $this->isTest() ? 'sandbox' : 'production',
        ]));
    }

    public function createCharge(ChargeRequest $charge): ChargeResult
    {
        $now = CarbonImmutable::now();
        // A minute inside each bound: the request takes time to arrive.
        $expiresAt = CarbonImmutable::instance($charge->expiresAt)
            ->max($now->addMinutes(self::MIN_MINUTES + 1))
            ->min($now->addMinutes(self::MAX_MINUTES - 1));

        $description = Str::limit($charge->description !== '' ? $charge->description : 'Pagamento', 250, '');

        $body = array_filter([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($charge->currency),
                    'unit_amount' => $charge->amountCents,
                    'product_data' => ['name' => $description],
                ],
                'quantity' => 1,
            ]],
            'client_reference_id' => $charge->reference,
            'metadata' => ['reference' => $charge->reference],
            'payment_intent_data' => [
                'description' => $description,
                'metadata' => ['reference' => $charge->reference],
            ],
            'expires_at' => $expiresAt->getTimestamp(),
            'success_url' => $this->successUrl($charge),
            'locale' => 'pt-BR',
            'customer_email' => $charge->payerEmail && filter_var($charge->payerEmail, FILTER_VALIDATE_EMAIL) ? $charge->payerEmail : null,
        ], fn ($value) => $value !== null);

        $json = $this->call(
            fn (PendingRequest $http) => $http->withHeaders(['Idempotency-Key' => $charge->reference])->post('/v1/checkout/sessions', $body),
            'create_checkout',
        );

        return new ChargeResult(
            providerPaymentId: (string) ($json['id'] ?? ''),
            status: self::statusFrom($json),
            pixCode: null,
            paymentUrl: $json['url'] ?? null,
            expiresAt: isset($json['expires_at']) ? CarbonImmutable::createFromTimestamp((int) $json['expires_at']) : $expiresAt,
        );
    }

    public function fetchStatus(FlowPayment $payment): ChargeStatus
    {
        if (! $payment->provider_payment_id) {
            return new ChargeStatus(FlowPaymentStatus::Pending);
        }

        $session = $this->call(fn (PendingRequest $http) => $http->get(
            '/v1/checkout/sessions/'.rawurlencode($payment->provider_payment_id),
            ['expand' => ['payment_intent']],
        ), 'fetch_checkout');

        $status = self::statusFrom($session);
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;

        return new ChargeStatus(
            status: $status,
            paidAt: $status === FlowPaymentStatus::Paid ? CarbonImmutable::now() : null,
            providerStatus: trim(($session['status'] ?? '').'/'.($session['payment_status'] ?? ''), '/'),
            providerPaymentId: $intent['id'] ?? null,
        );
    }

    public function webhookReferences(Request $request): array
    {
        // The body is a pointer, and only ever used as one: the session is
        // read back with the workspace's key, so the signing secret Stripe
        // returns on registration is not needed — and not kept, which is one
        // fewer secret to store.
        $object = (array) $request->input('data.object', []);

        $reference = $object['client_reference_id'] ?? ($object['metadata']['reference'] ?? null);

        return is_string($reference) && str_starts_with($reference, 'pingly-fp-') ? [$reference] : [];
    }

    public function cancelCharge(FlowPayment $payment): void
    {
        if (! $payment->provider_payment_id) {
            return;
        }

        // Only an open session can be expired; one whose Pix is already on the
        // customer's screen ends when that Pix does, and Stripe says so with a
        // refusal the caller swallows.
        $this->call(
            fn (PendingRequest $http) => $http->post('/v1/checkout/sessions/'.rawurlencode($payment->provider_payment_id).'/expire'),
            'expire_checkout',
        );
    }

    public function registerWebhook(string $url, string $secret): array
    {
        $existing = $this->call(fn (PendingRequest $http) => $http->get('/v1/webhook_endpoints', ['limit' => 100]), 'list_webhooks');

        foreach ((array) ($existing['data'] ?? []) as $endpoint) {
            if (is_array($endpoint) && ($endpoint['url'] ?? null) === $url && isset($endpoint['id'])) {
                return ['webhook_ids' => [(string) $endpoint['id']]];
            }
        }

        $json = $this->call(fn (PendingRequest $http) => $http->post('/v1/webhook_endpoints', [
            'url' => $url,
            'enabled_events' => self::WEBHOOK_EVENTS,
            'description' => 'Pingly · fluxos',
            'metadata' => ['integration_id' => (string) $this->integration->id],
        ]), 'register_webhook');

        return ['webhook_ids' => array_values(array_filter([$json['id'] ?? null]))];
    }

    public function unregisterWebhook(array $meta): void
    {
        foreach ((array) ($meta['webhook_ids'] ?? []) as $id) {
            try {
                $this->call(fn (PendingRequest $http) => $http->delete('/v1/webhook_endpoints/'.rawurlencode((string) $id)), 'delete_webhook');
            } catch (\Throwable) {
                // See OpenPixGateway::unregisterWebhook().
            }
        }
    }

    /**
     * Where the customer lands after paying: the workspace's own page when it
     * set one, otherwise a plain "payment received, go back to the chat" page
     * of ours — Stripe requires somewhere.
     */
    private function successUrl(ChargeRequest $charge): string
    {
        $custom = trim((string) $this->integration->setting('success_url', ''));

        if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $custom)) {
            return $custom;
        }

        return route('flow-payments.done', ['reference' => $charge->reference]);
    }

    /** @param  array<string, mixed>  $session */
    private static function statusFrom(array $session): FlowPaymentStatus
    {
        $status = (string) ($session['status'] ?? 'open');
        $paymentStatus = (string) ($session['payment_status'] ?? 'unpaid');
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;

        return match (true) {
            in_array($paymentStatus, ['paid', 'no_payment_required'], true) => FlowPaymentStatus::Paid,
            $status === 'expired' => FlowPaymentStatus::Expired,
            // A delayed method (Pix, boleto) that the customer started and
            // that then failed or expired on Stripe's side.
            $status === 'complete' && ($intent['status'] ?? null) === 'canceled' => FlowPaymentStatus::Failed,
            default => FlowPaymentStatus::Pending,
        };
    }
}
