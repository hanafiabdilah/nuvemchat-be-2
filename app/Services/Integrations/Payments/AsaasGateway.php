<?php

namespace App\Services\Integrations\Payments;

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Integration\IntegrationProvider;
use App\Models\FlowPayment;
use App\Models\Integration;
use App\Services\Integrations\Concerns\CallsProvider;
use App\Services\Integrations\ManagesWebhooks;
use App\Support\Errors\UpstreamError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Asaas (API v3): a Pix charge, or a payment link that also takes boleto and
 * card, on the workspace's own account.
 *
 * Four things about Asaas shape this class:
 *
 *  - Authenticated with an `access_token` header (not Bearer), and the key
 *    itself says which environment it belongs to: `$aact_hmlg_` is sandbox,
 *    and a key from one environment is refused by the other.
 *  - Every charge belongs to a customer, and a customer needs a CPF/CNPJ. The
 *    payment node refuses before calling when there is none.
 *  - There is no idempotency header. Our reference goes out as
 *    `externalReference` and is looked up before anything is created, which is
 *    what Asaas itself recommends after a timeout.
 *  - A dynamic QR stays payable long after its due date, so the charge is
 *    deleted at the node's deadline (CancelsCharges).
 */
class AsaasGateway implements PaymentGateway, ManagesWebhooks, CancelsCharges
{
    use CallsProvider;

    public const PRODUCTION_URL = 'https://api.asaas.com/v3';

    public const SANDBOX_URL = 'https://api-sandbox.asaas.com/v3';

    public const WEBHOOK_EVENTS = [
        'PAYMENT_RECEIVED',
        'PAYMENT_CONFIRMED',
        'PAYMENT_OVERDUE',
        'PAYMENT_DELETED',
        'PAYMENT_RESTORED',
        'PAYMENT_REFUNDED',
        'PAYMENT_UPDATED',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
        'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
    ];

    /** `CONFIRMED` is card money not yet available — paid, as far as the customer is concerned. */
    private const PAID = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];

    private const REVERSED = [
        'REFUNDED',
        'REFUND_REQUESTED',
        'REFUND_IN_PROGRESS',
        'CHARGEBACK_REQUESTED',
        'CHARGEBACK_DISPUTE',
        'AWAITING_CHARGEBACK_REVERSAL',
    ];

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->isSandbox() ? self::SANDBOX_URL : self::PRODUCTION_URL)
            ->withHeaders([
                'access_token' => (string) $this->integration->credential('api_key'),
                // Mandatory for accounts created after June 2024.
                'User-Agent' => 'Pingly/1.0',
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(8);
    }

    protected function errorFrom(Response $response): array
    {
        $errors = collect((array) $response->json('errors', []))
            ->map(fn ($error) => is_array($error) ? ($error['description'] ?? null) : null)
            ->filter()
            ->implode('; ');

        $code = $response->json('errors.0.code');

        return [$errors !== '' ? $errors : null, is_string($code) ? $code : null];
    }

    private function isSandbox(): bool
    {
        return str_starts_with((string) $this->integration->credential('api_key'), '$aact_hmlg_');
    }

    public function verify(): array
    {
        // The cheapest authenticated read: a key Asaas does not know is a 401.
        $this->call(fn (PendingRequest $http) => $http->get('/finance/balance'), 'verify');

        $account = [];

        try {
            $info = $this->call(fn (PendingRequest $http) => $http->get('/myAccount/commercialInfo'), 'account_info');
            $account = [
                'account_name' => $info['companyName'] ?? $info['name'] ?? null,
                'account_email' => $info['email'] ?? null,
                // Not APPROVED means Asaas may refuse charges until the
                // commercial data is completed — shown on the card.
                'account_status' => $info['status'] ?? null,
            ];
        } catch (\Throwable) {
            // A key scoped without account reads still charges fine.
        }

        return array_filter(array_merge($account, [
            'environment' => $this->isSandbox() ? 'sandbox' : 'production',
        ]));
    }

    public function createCharge(ChargeRequest $charge): ChargeResult
    {
        $document = $charge->payerDocument ? preg_replace('/\D+/', '', $charge->payerDocument) : '';

        if (! in_array(strlen((string) $document), [11, 14], true)) {
            throw UpstreamError::exception(
                IntegrationProvider::Asaas->upstream(),
                'cpfCnpj is required',
                'payer_document_required',
                422,
                ['integration_id' => $this->integration->id, 'action' => 'create_payment'],
            );
        }

        $payment = $this->findByReference($charge->reference) ?? $this->call(
            fn (PendingRequest $http) => $http->post('/payments', array_filter([
                'customer' => $this->customerId($charge, (string) $document),
                'billingType' => $charge->method === 'checkout' ? 'UNDEFINED' : 'PIX',
                'value' => round($charge->amountCents / 100, 2),
                // A date, in Brazil's calendar: the day the deadline falls on.
                'dueDate' => CarbonImmutable::instance($charge->expiresAt)->setTimezone('America/Sao_Paulo')->format('Y-m-d'),
                'description' => Str::limit($charge->description, 500, ''),
                'externalReference' => $charge->reference,
            ], fn ($value) => $value !== null && $value !== '')),
            'create_payment',
        );

        $pixCode = null;

        if ($charge->method === 'pix' && isset($payment['id'])) {
            $qr = $this->call(
                fn (PendingRequest $http) => $http->get('/payments/'.rawurlencode((string) $payment['id']).'/pixQrCode'),
                'pix_qr_code',
            );
            $pixCode = is_string($qr['payload'] ?? null) && $qr['payload'] !== '' ? $qr['payload'] : null;
        }

        return new ChargeResult(
            providerPaymentId: (string) ($payment['id'] ?? ''),
            status: self::statusFrom($payment),
            pixCode: $pixCode,
            paymentUrl: $payment['invoiceUrl'] ?? null,
            // Asaas only knows a due *date*; the node's minute-precise deadline
            // is ours, and cancelCharge() enforces it.
            expiresAt: CarbonImmutable::instance($charge->expiresAt),
        );
    }

    public function fetchStatus(FlowPayment $payment): ChargeStatus
    {
        $data = $payment->provider_payment_id
            ? $this->call(fn (PendingRequest $http) => $http->get('/payments/'.rawurlencode($payment->provider_payment_id)), 'fetch_payment')
            : $this->findByReference($payment->reference);

        if (! is_array($data) || $data === []) {
            return new ChargeStatus(FlowPaymentStatus::Pending);
        }

        $status = self::statusFrom($data);
        $paidOn = $data['clientPaymentDate'] ?? $data['paymentDate'] ?? $data['confirmedDate'] ?? null;

        return new ChargeStatus(
            status: $status,
            paidAt: $status === FlowPaymentStatus::Paid
                ? ($paidOn ? CarbonImmutable::parse($paidOn, 'America/Sao_Paulo') : CarbonImmutable::now())
                : null,
            providerStatus: ($data['deleted'] ?? false) ? 'DELETED' : (string) ($data['status'] ?? ''),
            providerPaymentId: isset($data['id']) ? (string) $data['id'] : null,
        );
    }

    public function webhookReferences(Request $request): array
    {
        // Asaas sends back the token we registered. Not an HMAC, and not what
        // makes this safe — the payment is read back regardless — but a
        // delivery without it is not from our registration, so it costs
        // nothing at all.
        $token = (string) $request->header('asaas-access-token', '');

        if ($token === '' || ! hash_equals((string) $this->integration->webhook_token, $token)) {
            return [];
        }

        $reference = $request->input('payment.externalReference');

        return is_string($reference) && str_starts_with($reference, 'pingly-fp-') ? [$reference] : [];
    }

    public function cancelCharge(FlowPayment $payment): void
    {
        if (! $payment->provider_payment_id) {
            return;
        }

        $this->call(
            fn (PendingRequest $http) => $http->delete('/payments/'.rawurlencode($payment->provider_payment_id)),
            'delete_payment',
        );
    }

    public function registerWebhook(string $url, string $secret): array
    {
        $existing = $this->call(fn (PendingRequest $http) => $http->get('/webhooks', ['limit' => 100]), 'list_webhooks');

        foreach ((array) ($existing['data'] ?? []) as $webhook) {
            if (is_array($webhook) && ($webhook['url'] ?? null) === $url && isset($webhook['id'])) {
                return ['webhook_ids' => [(string) $webhook['id']]];
            }
        }

        $json = $this->call(fn (PendingRequest $http) => $http->post('/webhooks', array_filter([
            'name' => 'Pingly · fluxos',
            'url' => $url,
            // Where Asaas warns when deliveries start failing.
            'email' => $this->integration->meta['account']['account_email'] ?? null,
            'enabled' => true,
            'interrupted' => false,
            'apiVersion' => 3,
            'authToken' => $secret,
            'sendType' => 'NON_SEQUENTIALLY',
            'events' => self::WEBHOOK_EVENTS,
        ], fn ($value) => $value !== null)), 'register_webhook');

        return ['webhook_ids' => array_values(array_filter([$json['id'] ?? null]))];
    }

    public function unregisterWebhook(array $meta): void
    {
        foreach ((array) ($meta['webhook_ids'] ?? []) as $id) {
            try {
                $this->call(fn (PendingRequest $http) => $http->delete('/webhooks/'.rawurlencode((string) $id)), 'delete_webhook');
            } catch (\Throwable) {
                // A revoked key cannot remove its own webhook; the token in
                // the URL no longer resolves, and we acknowledge and ignore.
            }
        }
    }

    /**
     * The charge we already created under this reference, if any — the only
     * idempotency Asaas offers.
     *
     * @return array<string, mixed>|null
     */
    private function findByReference(string $reference): ?array
    {
        $json = $this->call(fn (PendingRequest $http) => $http->get('/payments', [
            'externalReference' => $reference,
            'limit' => 1,
        ]), 'find_payment');

        $found = $json['data'][0] ?? null;

        return is_array($found) && ! ($found['deleted'] ?? false) ? $found : null;
    }

    /**
     * The payer as an Asaas customer: found by CPF/CNPJ, created when new.
     *
     * Created with Asaas's own notifications off — the bot is already sending
     * the Pix in the chat, and a second copy by SMS and e-mail from a sender
     * the customer does not recognise reads like a scam.
     */
    private function customerId(ChargeRequest $charge, string $document): string
    {
        $found = $this->call(fn (PendingRequest $http) => $http->get('/customers', [
            'cpfCnpj' => $document,
            'limit' => 1,
        ]), 'find_customer');

        if (isset($found['data'][0]['id'])) {
            return (string) $found['data'][0]['id'];
        }

        $name = trim((string) $charge->payerName);

        $created = $this->call(fn (PendingRequest $http) => $http->post('/customers', array_filter([
            'name' => $name !== '' && ! ctype_digit(str_replace(['+', ' '], '', $name)) ? Str::limit($name, 100, '') : 'Cliente',
            'cpfCnpj' => $document,
            'email' => $charge->payerEmail && filter_var($charge->payerEmail, FILTER_VALIDATE_EMAIL) ? $charge->payerEmail : null,
            'notificationDisabled' => true,
        ], fn ($value) => $value !== null)), 'create_customer');

        return (string) ($created['id'] ?? '');
    }

    /** @param  array<string, mixed>  $payment */
    private static function statusFrom(array $payment): FlowPaymentStatus
    {
        $status = strtoupper((string) ($payment['status'] ?? 'PENDING'));

        return match (true) {
            (bool) ($payment['deleted'] ?? false) => FlowPaymentStatus::Failed,
            in_array($status, self::PAID, true) => FlowPaymentStatus::Paid,
            in_array($status, self::REVERSED, true) => FlowPaymentStatus::Failed,
            // OVERDUE included: a date passing is not the node's deadline,
            // which is minute-precise and decided on our side.
            default => FlowPaymentStatus::Pending,
        };
    }
}
