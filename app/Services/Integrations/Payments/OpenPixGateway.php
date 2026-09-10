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
 * OpenPix (Woovi): Pix charges on the workspace's own account.
 *
 * Authenticated with the AppID as the bare `Authorization` header — no
 * "Bearer", which is OpenPix's convention, not a typo.
 *
 * Idempotent by construction: our reference is sent as `correlationID` with
 * `return_existing=true`, so a create retried after a timeout returns the
 * charge it already made instead of a second one the customer could pay twice.
 */
class OpenPixGateway implements PaymentGateway, ManagesWebhooks
{
    use CallsProvider;

    public const PRODUCTION_URL = 'https://api.openpix.com.br';

    public const SANDBOX_URL = 'https://api.woovi-sandbox.com';

    /**
     * The two events that end a charge. Creation is ours, so we already know;
     * everything else OpenPix can send (movements, refunds) is not something a
     * waiting flow reacts to.
     */
    public const WEBHOOK_EVENTS = ['OPENPIX:CHARGE_COMPLETED', 'OPENPIX:CHARGE_EXPIRED'];

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['Authorization' => (string) $this->integration->credential('app_id')])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(8);
    }

    protected function errorFrom(Response $response): array
    {
        $message = $response->json('error')
            ?? $response->json('errors.0.message')
            ?? $response->json('message');

        return [is_string($message) ? $message : null, null];
    }

    private function baseUrl(): string
    {
        return $this->integration->setting('sandbox') ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    public function verify(): array
    {
        // The cheapest authenticated read there is: an AppID OpenPix does not
        // know is refused here, before anything is created.
        $this->call(fn (PendingRequest $http) => $http->get('/api/v1/webhook'), 'verify');

        return [
            'environment' => $this->integration->setting('sandbox') ? 'sandbox' : 'production',
        ];
    }

    public function createCharge(ChargeRequest $charge): ChargeResult
    {
        $body = [
            'correlationID' => $charge->reference,
            'value' => $charge->amountCents,
            'comment' => Str::limit($charge->description, 140, ''),
            'expiresIn' => max(60, (int) now()->diffInSeconds($charge->expiresAt)),
        ];

        if ($customer = $this->customer($charge)) {
            $body['customer'] = $customer;
        }

        $json = $this->call(
            fn (PendingRequest $http) => $http->post('/api/v1/charge?return_existing=true', $body),
            'create_charge',
        );

        $data = is_array($json['charge'] ?? null) ? $json['charge'] : $json;

        return new ChargeResult(
            providerPaymentId: (string) ($data['globalID'] ?? $data['correlationID'] ?? $charge->reference),
            status: self::statusFrom((string) ($data['status'] ?? 'ACTIVE')),
            pixCode: $data['brCode'] ?? $json['brCode'] ?? null,
            paymentUrl: $data['paymentLinkUrl'] ?? null,
            expiresAt: isset($data['expiresDate'])
                ? CarbonImmutable::parse($data['expiresDate'])
                : CarbonImmutable::instance($charge->expiresAt),
        );
    }

    public function fetchStatus(FlowPayment $payment): ChargeStatus
    {
        // The correlation id is accepted wherever the charge id is, and it is
        // the one value we are sure to have: it is ours.
        $json = $this->call(
            fn (PendingRequest $http) => $http->get('/api/v1/charge/'.rawurlencode($payment->reference)),
            'fetch_charge',
        );

        $data = is_array($json['charge'] ?? null) ? $json['charge'] : [];
        $raw = (string) ($data['status'] ?? 'ACTIVE');
        $status = self::statusFrom($raw);

        return new ChargeStatus(
            status: $status,
            paidAt: $status === FlowPaymentStatus::Paid
                ? CarbonImmutable::parse($data['paidAt'] ?? $data['updatedAt'] ?? now())
                : null,
            providerStatus: $raw,
        );
    }

    public function webhookReferences(Request $request): array
    {
        // `charge` on charge events, `pix.charge` on transaction events. The
        // registration ping (`{"evento":"teste_webhook"}`) carries neither and
        // is acknowledged with nothing to do.
        $candidates = [
            $request->input('charge.correlationID'),
            $request->input('pix.charge.correlationID'),
        ];

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => is_string($value) && $value !== '',
        )));
    }

    public function registerWebhook(string $url, string $secret): array
    {
        // Registering twice would make OpenPix call us twice per payment, which
        // is harmless (settling is idempotent) but noisy — so reuse whatever is
        // already pointed at this URL.
        $existing = $this->call(fn (PendingRequest $http) => $http->get('/api/v1/webhook', ['url' => $url]), 'list_webhooks');

        $known = [];
        foreach ((array) ($existing['webhooks'] ?? []) as $webhook) {
            if (($webhook['url'] ?? null) === $url && isset($webhook['event'])) {
                $known[$webhook['event']] = $webhook['id'] ?? null;
            }
        }

        $ids = [];
        foreach (self::WEBHOOK_EVENTS as $event) {
            if (array_key_exists($event, $known)) {
                $ids[$event] = $known[$event];

                continue;
            }

            $json = $this->call(fn (PendingRequest $http) => $http->post('/api/v1/webhook', [
                'webhook' => [
                    'name' => 'Pingly · fluxos',
                    'event' => $event,
                    'url' => $url,
                    'authorization' => $secret,
                    'isActive' => true,
                ],
            ]), 'register_webhook');

            $ids[$event] = $json['webhook']['id'] ?? null;
        }

        return ['webhook_ids' => array_filter($ids)];
    }

    public function unregisterWebhook(array $meta): void
    {
        foreach ((array) ($meta['webhook_ids'] ?? []) as $id) {
            try {
                $this->call(
                    fn (PendingRequest $http) => $http->delete('/api/v1/webhook/'.rawurlencode((string) $id)),
                    'delete_webhook',
                );
            } catch (\Throwable) {
                // A key that was revoked cannot delete its own webhooks, and
                // that is the usual reason somebody removes an integration.
                // The webhook then points at a token that no longer resolves,
                // which we acknowledge and ignore.
            }
        }
    }

    /**
     * The payer, when there is enough of one.
     *
     * OpenPix refuses a customer with a name and nothing else, so one is only
     * sent when a CPF/CNPJ was collected — a charge without a customer is
     * perfectly valid, a charge refused over an optional field is not.
     *
     * @return array<string, string>|null
     */
    private function customer(ChargeRequest $charge): ?array
    {
        $taxId = $charge->payerDocument ? preg_replace('/\D+/', '', $charge->payerDocument) : null;

        if (! $charge->payerName || ! $taxId || ! in_array(strlen($taxId), [11, 14], true)) {
            return null;
        }

        return array_filter([
            'name' => Str::limit($charge->payerName, 100, ''),
            'taxID' => $taxId,
            'email' => $charge->payerEmail && filter_var($charge->payerEmail, FILTER_VALIDATE_EMAIL) ? $charge->payerEmail : null,
        ]);
    }

    private static function statusFrom(string $status): FlowPaymentStatus
    {
        return match (strtoupper($status)) {
            'COMPLETED' => FlowPaymentStatus::Paid,
            'EXPIRED' => FlowPaymentStatus::Expired,
            default => FlowPaymentStatus::Pending,
        };
    }
}
