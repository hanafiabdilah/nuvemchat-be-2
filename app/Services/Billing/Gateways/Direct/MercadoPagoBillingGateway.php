<?php

namespace App\Services\Billing\Gateways\Direct;

use App\Models\Tenant;
use App\Services\Billing\Gateways\AuthorisesRecurringSeparately;
use App\Services\Billing\Gateways\BillingGateway;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\HoldsRecurringAuthorisations;
use App\Services\Billing\Gateways\SavesCards;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Brazil, when PAYMENT_METHOD=direct: Pingly's own Mercado Pago account.
 *
 * Two rails, and they are not the same kind of thing:
 *
 *  - **Pix** is a plain `/v1/payments` charge — one QR per cycle, settled by
 *    webhook, exactly like the payment-service Pix it replaces.
 *  - **Card** is a *preapproval* (Mercado Pago's own subscriptions). Mercado
 *    Pago will not charge a stored card without its security code, so a card
 *    we keep and charge ourselves every month cannot work here — the only way
 *    a Brazilian card renews unattended is to let Mercado Pago hold the
 *    schedule. That is why this gateway `renewsItself()`: `billing:charge-
 *    renewals` must leave these alone, every cycle arrives as a
 *    `subscription_authorized_payment` webhook, and a cancellation has to be
 *    told to Mercado Pago or it keeps charging.
 *
 * **Saved cards** (Mercado Pago customers & cards, `SavesCards`) sit on top of
 * both: a kept card is still charged through a fresh token (card id + CVV typed
 * again), either into a new preapproval (subscription) or into a one-off
 * `/v1/payments` charge (`once` — a balance top-up).
 *
 * Errors are translated under UpstreamProvider::PaymentService, not
 * ::MercadoPago: that dictionary is written for a *customer's own* account
 * ("confira a variável do nó"), and this account is ours.
 */
class MercadoPagoBillingGateway implements AuthorisesRecurringSeparately, BillingGateway, HoldsRecurringAuthorisations, SavesCards
{
    /** How a preapproval is stored in `subscriptions.payment_instrument_id`. */
    public const INSTRUMENT_PREFIX = 'mp_preapproval:';

    /**
     * external_reference on a preapproval. Its charges arrive as `payment`
     * notifications carrying this, and no invoice ever has it — the webhook
     * uses the prefix to skip them instead of logging "no matching invoice"
     * every month.
     */
    public const RECURRING_REFERENCE_PREFIX = 'pingly-mpsub-';

    public function name(): string
    {
        return BillingGateways::MERCADOPAGO;
    }

    public function isConfigured(): bool
    {
        return DirectBillingConfig::mpAccessToken() !== null;
    }

    public function paymentMethods(?string $currency): array
    {
        if (! $this->isConfigured() || strtoupper((string) $currency) !== 'BRL') {
            return [];
        }

        return [
            [
                'method' => 'card',
                'instruction_type' => 'card',
                'currencies' => ['BRL'],
                'max_installments' => 1,
                'public_key' => DirectBillingConfig::mpPublicKey(),
                // A preapproval renews on Mercado Pago's own schedule, which is
                // what makes "renova automaticamente" true here.
                'merchant_initiated_cards' => DirectBillingConfig::mpPublicKey() !== null,
            ],
            [
                'method' => 'pix',
                'instruction_type' => 'pix',
                'currencies' => ['BRL'],
                'max_installments' => 1,
                'public_key' => null,
                'merchant_initiated_cards' => false,
            ],
        ];
    }

    public function cardSession(Tenant $tenant): array
    {
        return [
            'sdk' => 'mercadopago',
            'public_key' => DirectBillingConfig::mpPublicKey(),
            'provider' => $this->name(),
            // The page draws its own card fields (saved cards + CVV, or a new
            // card that is kept) instead of the payment brick.
            'saved_cards' => true,
        ];
    }

    public function createPayment(array $payload, string $idempotencyKey): array
    {
        $method = $payload['payment_method'] ?? null;

        if ($method === 'pix') {
            return ['data' => $this->createPix($payload, $idempotencyKey)];
        }

        // One charge, not a subscription: a balance top-up on a saved card.
        if ($method === 'card' && filled($payload['card_token'] ?? null) && ($payload['once'] ?? false)) {
            return ['data' => $this->createCardPayment($payload, $idempotencyKey)];
        }

        if ($method === 'card' && filled($payload['card_token'] ?? null)) {
            return ['data' => $this->createPreapproval($payload, $idempotencyKey)];
        }

        // A stored-card charge (`instrument_id`) or anything else: never valid
        // here — the preapproval charges itself. Reaching this is a routing bug.
        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            "Mercado Pago direct billing cannot take a '{$method}' charge of this shape.",
            upstreamCode: 'instrument_not_chargeable',
            status: 422,
        );
    }

    public function getPayment(string $id): array
    {
        return $this->normalizePayment($this->decode($this->http()->get("/v1/payments/{$id}")));
    }

    /** @return array<string, mixed> Raw Mercado Pago preapproval. */
    public function getPreapproval(string $id): array
    {
        return $this->decode($this->http()->get("/preapproval/{$id}"));
    }

    /**
     * One cycle of a preapproval: `{id, preapproval_id, status, payment{id,
     * status}, transaction_amount, debit_date}`.
     *
     * @return array<string, mixed>
     */
    public function getAuthorizedPayment(string $id): array
    {
        return $this->decode($this->http()->get("/authorized_payments/{$id}"));
    }

    public function renewsItself(string $instrumentId): bool
    {
        return str_starts_with($instrumentId, self::INSTRUMENT_PREFIX);
    }

    /**
     * Paused on cancel-at-period-end (so "Resume" can undo it — `cancelled` is
     * terminal at Mercado Pago), authorised again on resume, cancelled for good
     * when the plan is suspended or replaced.
     */
    public function setRecurringState(string $instrumentId, string $state): void
    {
        if (! $this->renewsItself($instrumentId)) {
            return;
        }

        $status = match ($state) {
            'paused' => 'paused',
            'active' => 'authorized',
            default => 'cancelled',
        };

        $response = $this->http()->put('/preapproval/'.$this->preapprovalId($instrumentId), ['status' => $status]);

        // Already gone is the outcome we wanted.
        if ($response->status() === 404) {
            return;
        }

        $this->decode($response);
    }

    /**
     * A scheduled downgrade: every later debit of the preapproval charges the
     * new price. Only the amount moves — Mercado Pago keeps the frequency, which
     * is why a downgrade that also changes the cycle is refused before this.
     */
    public function updateRecurringAmount(string $instrumentId, int $amountCents, string $currency): void
    {
        if (! $this->renewsItself($instrumentId)) {
            return;
        }

        $this->decode($this->http()->put('/preapproval/'.$this->preapprovalId($instrumentId), [
            'auto_recurring' => [
                'transaction_amount' => $this->decimal($amountCents),
                'currency_id' => $currency,
            ],
        ]));
    }

    public function preapprovalId(string $instrumentId): string
    {
        return Str::after($instrumentId, self::INSTRUMENT_PREFIX);
    }

    /** `GET /users/me` — authenticated, creates nothing. For the Back Office probe. */
    public function verifyCredentials(): array
    {
        return $this->decode($this->http()->get('/users/me'));
    }

    // --- Saved cards (customers & cards) ------------------------------------

    /**
     * Mercado Pago keeps one customer per email, so a lookup comes first; a
     * create that loses a race to another request is answered with "already
     * exists", and the lookup is simply made again.
     */
    public function findOrCreateCustomer(array $customer): string
    {
        $email = strtolower(trim((string) ($customer['email'] ?? '')));

        if ($email === '') {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'Mercado Pago customers need an email.',
                upstreamCode: 'payment_refused',
                status: 422,
            );
        }

        if ($found = $this->searchCustomer($email)) {
            return $found;
        }

        $payer = $this->payer(['email' => $email] + $customer);
        $response = $this->http()->post('/v1/customers', array_filter([
            'email' => $email,
            'first_name' => $payer['first_name'] ?? null,
            'last_name' => $payer['last_name'] ?? null,
            'identification' => $payer['identification'] ?? null,
        ], fn ($value) => $value !== null));

        if (! $response->successful() && ($found = $this->searchCustomer($email))) {
            return $found;
        }

        return (string) ($this->decode($response)['id'] ?? '');
    }

    public function saveCard(string $customerId, string $cardToken): array
    {
        $card = $this->decode($this->http()->post('/v1/customers/'.rawurlencode($customerId).'/cards', [
            'token' => $cardToken,
        ]));

        return [
            'id' => (string) ($card['id'] ?? ''),
            'brand' => $card['payment_method']['id'] ?? null,
            'payment_type' => $card['payment_method']['payment_type_id'] ?? null,
            'issuer_id' => isset($card['issuer']['id']) ? (string) $card['issuer']['id'] : null,
            'first_six' => $card['first_six_digits'] ?? null,
            'last_four' => $card['last_four_digits'] ?? null,
            'exp_month' => isset($card['expiration_month']) ? (int) $card['expiration_month'] : null,
            'exp_year' => isset($card['expiration_year']) ? (int) $card['expiration_year'] : null,
            'holder_name' => $card['cardholder']['name'] ?? null,
        ];
    }

    public function deleteCard(string $customerId, string $cardId): void
    {
        $response = $this->http()->delete('/v1/customers/'.rawurlencode($customerId).'/cards/'.rawurlencode($cardId));

        if ($response->status() === 404) {
            return;
        }

        $this->decode($response);
    }

    protected function searchCustomer(string $email): ?string
    {
        $results = $this->decode($this->http()->get('/v1/customers/search', ['email' => $email]))['results'] ?? [];
        $id = $results[0]['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    // --- Charges ------------------------------------------------------------

    /**
     * A single card charge (a top-up) on a saved card, with the token minted
     * from its id and the CVV. `payer.type = customer` ties the charge to the
     * customer the card belongs to — the token alone is not enough for that.
     *
     * Synchronous like any card: the response carries the outcome, and
     * `in_process` (manual review) settles later through the webhook.
     */
    protected function createCardPayment(array $payload, string $idempotencyKey): array
    {
        $response = $this->http($idempotencyKey)->post('/v1/payments', array_filter([
            'transaction_amount' => $this->decimal((int) $payload['amount']),
            'token' => $payload['card_token'],
            'installments' => 1,
            'payment_method_id' => $payload['card_brand'] ?? null,
            'issuer_id' => filled($payload['card_issuer_id'] ?? null) ? (int) $payload['card_issuer_id'] : null,
            'description' => $payload['description'] ?? null,
            'external_reference' => $payload['order_reference'] ?? null,
            'notification_url' => $this->notificationUrl(),
            'payer' => filled($payload['customer_id'] ?? null)
                ? ['type' => 'customer', 'id' => $payload['customer_id']]
                : $this->payer($payload['customer'] ?? []),
            'metadata' => $payload['metadata'] ?? null,
        ], fn ($value) => $value !== null));

        return $this->normalizePayment($this->decode($response));
    }

    protected function createPix(array $payload, string $idempotencyKey): array
    {
        $response = $this->http($idempotencyKey)->post('/v1/payments', array_filter([
            'transaction_amount' => $this->decimal((int) $payload['amount']),
            'description' => $payload['description'] ?? null,
            'payment_method_id' => 'pix',
            // Ours, and what makes a lost payment findable after a timeout.
            'external_reference' => $payload['order_reference'] ?? null,
            // Offset-bearing: without it Mercado Pago silently applies 24h.
            'date_of_expiration' => isset($payload['expires_at'])
                ? \Illuminate\Support\Carbon::parse($payload['expires_at'])->format('Y-m-d\TH:i:s.vP')
                : null,
            'notification_url' => $this->notificationUrl(),
            'payer' => $this->payer($payload['customer'] ?? []),
            'metadata' => $payload['metadata'] ?? null,
        ], fn ($value) => $value !== null));

        return $this->normalizePayment($this->decode($response));
    }

    /**
     * The first card cycle *is* the preapproval: created authorised with the
     * browser's token, Mercado Pago charges it now and every cycle after.
     */
    protected function createPreapproval(array $payload, string $idempotencyKey): array
    {
        $customer = $payload['customer'] ?? [];
        $recurring = $payload['recurring'] ?? ['frequency' => 1, 'frequency_type' => 'months'];
        $subscriptionId = $payload['metadata']['subscription_id'] ?? null;

        $response = $this->http($idempotencyKey)->post('/preapproval', array_filter([
            'reason' => $payload['description'] ?? 'Pingly',
            'external_reference' => $subscriptionId !== null
                ? self::RECURRING_REFERENCE_PREFIX.$subscriptionId
                : ($payload['order_reference'] ?? null),
            'payer_email' => $customer['email'] ?? null,
            'card_token_id' => $payload['card_token'],
            'back_url' => DirectBillingConfig::returnUrl('/billing'),
            'status' => 'authorized',
            'auto_recurring' => [
                'frequency' => (int) $recurring['frequency'],
                'frequency_type' => $recurring['frequency_type'],
                'transaction_amount' => $this->decimal((int) $payload['amount']),
                'currency_id' => $payload['currency'] ?? 'BRL',
            ],
        ], fn ($value) => $value !== null));

        $preapproval = $this->decode($response);

        return [
            // No payment exists yet: the first charge arrives as an authorized
            // payment and is attached to this cycle's invoice then.
            'id' => null,
            'order_reference' => $payload['order_reference'] ?? null,
            'status' => match ($preapproval['status'] ?? null) {
                'authorized' => 'paid',
                'pending' => 'pending',
                default => 'declined',
            },
            'instrument' => isset($preapproval['id'])
                ? ['id' => self::INSTRUMENT_PREFIX.$preapproval['id']]
                : null,
            'decline' => ['category' => 'unknown', 'code' => $preapproval['status'] ?? null],
            'raw' => ['preapproval_id' => $preapproval['id'] ?? null],
        ];
    }

    /**
     * The renewals of a card subscription whose first cycle was already paid
     * through `/v1/payments`: a preapproval that starts charging when that
     * paid period ends, so nothing is charged twice and Mercado Pago does not
     * try to validate a card by charging it now (see the interface).
     */
    public function authoriseRecurring(array $payload, string $idempotencyKey): string
    {
        $customer = $payload['customer'] ?? [];
        $recurring = $payload['recurring'];

        $preapproval = $this->decode($this->http($idempotencyKey)->post('/preapproval', array_filter([
            'reason' => $payload['description'] ?? 'Pingly',
            'external_reference' => self::RECURRING_REFERENCE_PREFIX.$payload['subscription_id'],
            'payer_email' => $customer['email'] ?? null,
            'card_token_id' => $payload['card_token'],
            'back_url' => DirectBillingConfig::returnUrl('/billing'),
            'status' => 'authorized',
            'auto_recurring' => [
                'frequency' => (int) $recurring['frequency'],
                'frequency_type' => $recurring['frequency_type'],
                'transaction_amount' => $this->decimal((int) $payload['amount']),
                'currency_id' => $payload['currency'] ?? 'BRL',
                // Offset-bearing, like every Mercado Pago date.
                'start_date' => \Illuminate\Support\Carbon::instance($payload['start_date'])->format('Y-m-d\TH:i:s.vP'),
            ],
        ], fn ($value) => $value !== null)));

        if (! filled($preapproval['id'] ?? null) || ($preapproval['status'] ?? null) !== 'authorized') {
            // A half-made authorisation left behind could start charging on
            // its own later; nothing keeps it, so it is closed here.
            if (filled($preapproval['id'] ?? null)) {
                rescue(fn () => $this->http()->put('/preapproval/'.$preapproval['id'], ['status' => 'cancelled']), report: false);
            }

            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'Mercado Pago (direct billing): preapproval came back '.($preapproval['status'] ?? 'without a status'),
                upstreamCode: 'payment_refused',
                status: 422,
                context: ['gateway' => 'mercadopago', 'preapproval_id' => $preapproval['id'] ?? null],
            );
        }

        return self::INSTRUMENT_PREFIX.$preapproval['id'];
    }

    // --- Reading ------------------------------------------------------------

    /**
     * Mercado Pago's payment object in the shared vocabulary.
     *
     * `in_process` is a manual review that can still be approved hours later —
     * pending, never declined. `cancelled` with `expired` detail is a Pix
     * nobody paid.
     */
    public function normalizePayment(array $payment): array
    {
        $status = (string) ($payment['status'] ?? '');
        $detail = (string) ($payment['status_detail'] ?? '');
        $transaction = $payment['point_of_interaction']['transaction_data'] ?? [];

        return [
            'id' => isset($payment['id']) ? (string) $payment['id'] : null,
            'order_reference' => $payment['external_reference'] ?? null,
            'status' => match ($status) {
                'approved' => 'paid',
                'authorized' => 'authorized',
                'pending', 'in_process' => 'pending',
                'in_mediation', 'charged_back' => 'disputed',
                'refunded' => $detail === 'partially_refunded' ? 'partially_refunded' : 'refunded',
                'cancelled' => $detail === 'expired' ? 'expired' : 'declined',
                'rejected' => 'declined',
                default => 'unknown',
            },
            'instructions' => filled($transaction['qr_code'] ?? null) ? [
                'type' => 'pix',
                'qr_code' => $transaction['qr_code'],
                'qr_code_image' => filled($transaction['qr_code_base64'] ?? null)
                    ? 'data:image/png;base64,'.$transaction['qr_code_base64']
                    : null,
                'expires_at' => $payment['date_of_expiration'] ?? null,
            ] : [],
            'decline' => $status === 'rejected'
                ? ['category' => 'unknown', 'code' => $detail ?: null]
                : null,
            'metadata' => $payment['metadata'] ?? [],
        ];
    }

    // --- Plumbing -----------------------------------------------------------

    protected function payer(array $customer): array
    {
        $name = trim((string) ($customer['name'] ?? ''));
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);
        $type = strtoupper((string) ($customer['document_type'] ?? ''));

        return array_filter([
            'email' => $customer['email'] ?? null,
            'first_name' => $first ?: null,
            'last_name' => $last ?: null,
            // Mercado Pago only knows Brazilian documents; anything else would
            // be refused as a malformed identification.
            'identification' => in_array($type, ['CPF', 'CNPJ'], true) && filled($customer['document_number'] ?? null)
                ? ['type' => $type, 'number' => (string) $customer['document_number']]
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Sent per payment so no panel setup is needed for Pix — but only over
     * https: Mercado Pago refuses the whole payment for an http URL, which
     * would make every local environment unable to take a test charge.
     */
    protected function notificationUrl(): ?string
    {
        $url = DirectBillingConfig::mpWebhookUrl();

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /** Minor units to the decimal this API speaks. Always two places for BRL. */
    protected function decimal(int $cents): float
    {
        return round($cents / 100, 2);
    }

    protected function http(?string $idempotencyKey = null): PendingRequest
    {
        $token = DirectBillingConfig::mpAccessToken();

        if ($token === null) {
            throw UpstreamError::exception(
                UpstreamProvider::PaymentService,
                'Mercado Pago direct billing has no access token configured.',
                upstreamCode: 'payment_service_unconfigured',
                status: 503,
            );
        }

        $request = Http::baseUrl(DirectBillingConfig::MP_BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(15)
            ->timeout(45)
            // Connection failures only. Every write carries X-Idempotency-Key,
            // which Mercado Pago honours, so a retry returns the original.
            ->retry(2, 1500, fn ($e) => $e instanceof ConnectionException, throw: false);

        return $idempotencyKey ? $request->withHeaders(['X-Idempotency-Key' => $idempotencyKey]) : $request;
    }

    /**
     * Decode, or translate. The raw sentence goes to the log with a reference;
     * what leaves is our copy.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $body = $response->json();
        $message = is_array($body)
            ? trim(($body['message'] ?? '').' '.($body['cause'][0]['description'] ?? ''))
            : $response->body();
        $needle = Str::lower($message);

        $code = match (true) {
            // Ours: a wrong or revoked token is never the customer's to fix.
            in_array($response->status(), [401, 403], true) => 'payment_service_unconfigured',
            str_contains($needle, 'cc_rejected'),
            str_contains($needle, 'card token'),
            str_contains($needle, 'rejected') => 'payment_declined',
            $response->status() >= 400 && $response->status() < 500 => 'payment_refused',
            default => null,
        };

        throw UpstreamError::exception(
            UpstreamProvider::PaymentService,
            'Mercado Pago (direct billing): '.($message ?: $response->body()),
            upstreamCode: $code,
            status: $response->status(),
            context: ['gateway' => 'mercadopago', 'gateway_status' => $response->status()],
            details: UpstreamError::httpDetails($response),
        );
    }
}
