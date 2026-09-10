<?php

namespace App\Services\Integrations\Payments;

use App\Enums\Flow\FlowPaymentStatus;
use App\Models\FlowPayment;
use App\Models\Integration;
use App\Services\Integrations\Concerns\CallsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mercado Pago on the workspace's own account: a Pix payment, or a Checkout Pro
 * link that also takes cards and boleto.
 *
 * Both shapes carry our reference as `external_reference`, and that is the only
 * thing the rest of this class keys on. A checkout link has no payment behind
 * it until the customer tries to pay — and may have several (a declined card,
 * then a Pix) — so status is always read by searching payments for the
 * reference rather than by an id we may never have been given.
 *
 * No webhook to register: every payment and preference carries its own
 * `notification_url`, so this account needs nothing configured on the
 * provider's side. It must be https, which is why it is only sent when the
 * platform's URL is — Mercado Pago refuses the whole payment otherwise.
 */
class MercadoPagoGateway implements PaymentGateway
{
    use CallsProvider;

    public const BASE_URL = 'https://api.mercadopago.com';

    /** Mercado Pago refuses a Pix that expires sooner than this. */
    public const PIX_MIN_MINUTES = 30;

    public function __construct(private readonly Integration $integration) {}

    protected function integration(): Integration
    {
        return $this->integration;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) $this->integration->credential('access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(25)
            ->connectTimeout(8);
    }

    protected function errorFrom(Response $response): array
    {
        $causes = collect((array) $response->json('cause', []))
            ->map(fn ($cause) => is_array($cause) ? ($cause['description'] ?? null) : null)
            ->filter()
            ->implode('; ');

        $message = trim(((string) $response->json('message', '')).' '.$causes);
        $code = $response->json('cause.0.code') ?? $response->json('error');

        return [$message !== '' ? $message : null, $code !== null ? (string) $code : null];
    }

    public function verify(): array
    {
        $me = $this->call(fn (PendingRequest $http) => $http->get('/users/me'), 'verify');

        return array_filter([
            'account_id' => isset($me['id']) ? (string) $me['id'] : null,
            'account_name' => $me['nickname'] ?? null,
            'account_email' => $me['email'] ?? null,
            // Pix only exists in Brazil (MLB); anywhere else the checkout link
            // is the only method that will work, and the page says so.
            'site_id' => $me['site_id'] ?? null,
            'environment' => $this->isTest() ? 'sandbox' : 'production',
        ]);
    }

    public function createCharge(ChargeRequest $charge): ChargeResult
    {
        return $charge->method === 'checkout'
            ? $this->createCheckout($charge)
            : $this->createPix($charge);
    }

    private function createPix(ChargeRequest $charge): ChargeResult
    {
        $floor = CarbonImmutable::now()->addMinutes(self::PIX_MIN_MINUTES);
        $expiresAt = CarbonImmutable::instance($charge->expiresAt)->max($floor);

        $body = array_filter([
            'transaction_amount' => round($charge->amountCents / 100, 2),
            'description' => Str::limit($charge->description, 200, ''),
            'payment_method_id' => 'pix',
            'external_reference' => $charge->reference,
            'date_of_expiration' => $expiresAt->format('Y-m-d\TH:i:s.vP'),
            'notification_url' => $this->notificationUrl($charge),
            'payer' => $this->payer($charge),
        ], fn ($value) => $value !== null);

        $json = $this->call(
            fn (PendingRequest $http) => $http->withHeaders(['X-Idempotency-Key' => $charge->reference])->post('/v1/payments', $body),
            'create_pix',
        );

        $transaction = (array) ($json['point_of_interaction']['transaction_data'] ?? []);

        return new ChargeResult(
            providerPaymentId: (string) ($json['id'] ?? ''),
            status: self::statusFrom((string) ($json['status'] ?? 'pending'), $json['status_detail'] ?? null, 'pix'),
            pixCode: $transaction['qr_code'] ?? null,
            paymentUrl: $transaction['ticket_url'] ?? null,
            expiresAt: isset($json['date_of_expiration'])
                ? CarbonImmutable::parse($json['date_of_expiration'])
                : $expiresAt,
        );
    }

    private function createCheckout(ChargeRequest $charge): ChargeResult
    {
        $body = array_filter([
            'items' => [[
                'id' => $charge->reference,
                'title' => Str::limit($charge->description !== '' ? $charge->description : 'Pagamento', 250, ''),
                'quantity' => 1,
                'unit_price' => round($charge->amountCents / 100, 2),
                'currency_id' => $charge->currency,
            ]],
            'external_reference' => $charge->reference,
            'expires' => true,
            'expiration_date_from' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to' => CarbonImmutable::instance($charge->expiresAt)->format('Y-m-d\TH:i:s.vP'),
            'notification_url' => $this->notificationUrl($charge),
            'payer' => $charge->payerEmail && filter_var($charge->payerEmail, FILTER_VALIDATE_EMAIL)
                ? ['email' => $charge->payerEmail]
                : null,
        ], fn ($value) => $value !== null);

        $json = $this->call(
            fn (PendingRequest $http) => $http->withHeaders(['X-Idempotency-Key' => $charge->reference])->post('/checkout/preferences', $body),
            'create_checkout',
        );

        // The sandbox link is the only one a TEST- token's buyers can use.
        $url = $this->isTest()
            ? ($json['sandbox_init_point'] ?? $json['init_point'] ?? null)
            : ($json['init_point'] ?? null);

        return new ChargeResult(
            providerPaymentId: (string) ($json['id'] ?? ''),
            status: FlowPaymentStatus::Pending,
            pixCode: null,
            paymentUrl: $url,
            expiresAt: CarbonImmutable::instance($charge->expiresAt),
        );
    }

    public function fetchStatus(FlowPayment $payment): ChargeStatus
    {
        $json = $this->call(fn (PendingRequest $http) => $http->get('/v1/payments/search', [
            'external_reference' => $payment->reference,
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => 20,
        ]), 'fetch_payment');

        $results = array_values(array_filter((array) ($json['results'] ?? []), 'is_array'));

        // Any approved attempt settles it — on a checkout link a declined card
        // followed by a Pix is two results, and the second is the one that
        // counts.
        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'approved') {
                return new ChargeStatus(
                    status: FlowPaymentStatus::Paid,
                    paidAt: CarbonImmutable::parse($result['date_approved'] ?? now()),
                    providerStatus: 'approved',
                    providerPaymentId: isset($result['id']) ? (string) $result['id'] : null,
                );
            }
        }

        if ($results === []) {
            return new ChargeStatus(FlowPaymentStatus::Pending);
        }

        $latest = $results[0];
        $raw = (string) ($latest['status'] ?? 'pending');

        return new ChargeStatus(
            status: self::statusFrom($raw, $latest['status_detail'] ?? null, $payment->method),
            providerStatus: $raw,
            providerPaymentId: isset($latest['id']) ? (string) $latest['id'] : null,
        );
    }

    public function webhookReferences(Request $request): array
    {
        // Two notification dialects reach the same URL: Webhooks send
        // `?data.id=…&type=payment` (PHP turns that dot into `data_id`) plus a
        // JSON body; the older IPN sends `?id=…&topic=…`. Whichever it is, the
        // body is only a pointer — the payment is read back below.
        $type = (string) ($request->input('type') ?? $request->query('type') ?? $request->input('topic') ?? $request->query('topic') ?? '');
        $id = (string) ($request->input('data.id') ?? $request->query('data_id') ?? $request->query('id') ?? '');

        if ($id === '' && is_string($request->input('resource'))) {
            $id = basename((string) $request->input('resource'));
        }

        if ($id === '' || ! ctype_digit($id)) {
            return [];
        }

        $reference = match ($type) {
            'payment' => $this->call(fn (PendingRequest $http) => $http->get('/v1/payments/'.$id), 'webhook_payment')['external_reference'] ?? null,
            'merchant_order', 'topic_merchant_order_wh' => $this->call(fn (PendingRequest $http) => $http->get('/merchant_orders/'.$id), 'webhook_order')['external_reference'] ?? null,
            default => null,
        };

        return is_string($reference) && $reference !== '' ? [$reference] : [];
    }

    private function isTest(): bool
    {
        return str_starts_with((string) $this->integration->credential('access_token'), 'TEST-');
    }

    private function notificationUrl(ChargeRequest $charge): ?string
    {
        return $charge->notificationUrl && str_starts_with($charge->notificationUrl, 'https://')
            ? $charge->notificationUrl
            : null;
    }

    /**
     * Mercado Pago requires an e-mail on every Pix, and a WhatsApp contact
     * rarely has one. In order: what the flow collected, the default the
     * workspace set on the integration, and — so the charge is never refused
     * over it — an address on the platform's own domain.
     *
     * @return array<string, mixed>
     */
    private function payer(ChargeRequest $charge): array
    {
        $payer = ['email' => $this->payerEmail($charge)];

        $name = trim((string) $charge->payerName);
        if ($name !== '' && ! ctype_digit(str_replace(['+', ' '], '', $name))) {
            $parts = preg_split('/\s+/', $name) ?: [];
            $payer['first_name'] = Str::limit((string) array_shift($parts), 60, '');
            if ($parts !== []) {
                $payer['last_name'] = Str::limit(implode(' ', $parts), 60, '');
            }
        }

        $document = $charge->payerDocument ? preg_replace('/\D+/', '', $charge->payerDocument) : '';
        if (in_array(strlen((string) $document), [11, 14], true)) {
            $payer['identification'] = [
                'type' => strlen($document) === 11 ? 'CPF' : 'CNPJ',
                'number' => $document,
            ];
        }

        return $payer;
    }

    private function payerEmail(ChargeRequest $charge): string
    {
        foreach ([$charge->payerEmail, $this->integration->setting('payer_email')] as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return strtolower($candidate);
            }
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) ? $host : 'example.com';

        return 'pagador+'.Str::lower(Str::substr($charge->reference, -10)).'@'.$domain;
    }

    private static function statusFrom(string $status, ?string $detail, string $method): FlowPaymentStatus
    {
        return match (true) {
            $status === 'approved' => FlowPaymentStatus::Paid,
            in_array($status, ['refunded', 'charged_back'], true) => FlowPaymentStatus::Failed,
            // On a checkout link a declined card is one attempt, not the end of
            // the charge: the customer can try again until the link expires,
            // and the expiry is what decides.
            $method === 'checkout' => FlowPaymentStatus::Pending,
            $status === 'cancelled' && $detail === 'expired' => FlowPaymentStatus::Expired,
            in_array($status, ['rejected', 'cancelled'], true) => FlowPaymentStatus::Failed,
            default => FlowPaymentStatus::Pending,
        };
    }
}
