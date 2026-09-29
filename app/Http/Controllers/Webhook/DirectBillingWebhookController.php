<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\Gateways\BillingGateways;
use App\Services\Billing\Gateways\Direct\DirectBillingConfig;
use App\Services\Billing\Gateways\Direct\DLocalGoBillingGateway;
use App\Services\Billing\Gateways\Direct\MercadoPagoBillingGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Platform billing notifications when PAYMENT_METHOD=direct.
 *
 * Both gateways send a *pointer* — an id — and the state is always read back
 * from their API with our own credentials. That is what makes the body safe to
 * act on: a forged notification can only make us read one of our own payments
 * again, and the answer is whatever is true now. The signature is still
 * checked where one is sent, and a mismatch is refused outright.
 *
 * Kept on after PAYMENT_METHOD goes back to `payment_service`: a Pix or a
 * preapproval created in direct mode still reports here until it is settled or
 * ended.
 *
 * Always answers 200 — anything else schedules a retry of a delivery we have
 * already recorded, and `billing:reconcile` is the net for the ones that fail.
 */
class DirectBillingWebhookController extends Controller
{
    public function __construct(
        protected BillingService $billing,
        protected MercadoPagoBillingGateway $mercadoPago,
        protected DLocalGoBillingGateway $dlocalGo,
    ) {}

    // --- Mercado Pago (Brazil) --------------------------------------------

    public function mercadoPago(Request $request)
    {
        $type = (string) ($request->input('type') ?? $request->input('topic') ?? $request->query('type') ?? $request->query('topic'));
        $dataId = (string) ($request->input('data.id') ?? $request->query('data_id') ?? $request->query('data.id') ?? $request->input('id') ?? '');

        if ($dataId === '') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $signature = $this->mercadoPagoSignature($request, $dataId);

        // A redelivery carries the same x-request-id; updating rather than
        // inserting keeps it from dying on the unique key. Reading the payment
        // back twice changes nothing.
        $event = WebhookEvent::updateOrCreate(
            ['dedupe_key' => 'mercadopago-billing:'.$type.':'.$dataId.':'.($request->header('x-request-id') ?: Str::uuid())],
            [
                'provider' => 'mercadopago_billing',
                'event_type' => $type,
                'resource_id' => $dataId,
                'signature_valid' => $signature !== false,
                'payload' => $request->all(),
            ],
        );

        if ($signature === false) {
            Log::warning('Mercado Pago billing webhook with invalid signature', [
                'type' => $type,
                'data_id' => $dataId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'invalid-signature'], 200);
        }

        try {
            $this->processMercadoPago($type, $dataId);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Mercado Pago billing webhook processing failed', [
                'type' => $type,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    protected function processMercadoPago(string $type, string $dataId): void
    {
        $gateway = BillingGateways::MERCADOPAGO;
        $prefix = MercadoPagoBillingGateway::INSTRUMENT_PREFIX;

        match ($type) {
            'payment' => $this->mercadoPagoPayment($dataId),

            'subscription_preapproval', 'preapproval' => $this->billing->applyRecurringStateChange(
                $gateway,
                $prefix.$dataId,
                $this->mercadoPago->getPreapproval($dataId)['status'] ?? null,
            ),

            'subscription_authorized_payment', 'authorized_payment' => $this->mercadoPagoCycle($dataId),

            // merchant_order, point_integration_wh, … — nothing billing reads.
            default => null,
        };
    }

    protected function mercadoPagoPayment(string $id): void
    {
        $payment = $this->mercadoPago->getPayment($id);

        // A preapproval's own debits come through here too, carrying the
        // preapproval's reference — they are settled by the authorized-payment
        // notification that follows, which knows which subscription they renew.
        if (str_starts_with((string) ($payment['order_reference'] ?? ''), MercadoPagoBillingGateway::RECURRING_REFERENCE_PREFIX)) {
            return;
        }

        $this->billing->applyPaymentUpdate($payment, BillingGateways::MERCADOPAGO);
    }

    protected function mercadoPagoCycle(string $id): void
    {
        $cycle = $this->mercadoPago->getAuthorizedPayment($id);
        $preapprovalId = $cycle['preapproval_id'] ?? null;

        if (! $preapprovalId) {
            return;
        }

        // The nested payment carries the real outcome; the envelope says
        // `processed` once the debit ran, whichever way it went.
        $status = $cycle['payment']['status'] ?? $cycle['status'] ?? null;

        $this->billing->applyRecurringCharge(
            BillingGateways::MERCADOPAGO,
            MercadoPagoBillingGateway::INSTRUMENT_PREFIX.$preapprovalId,
            isset($cycle['payment']['id']) ? (string) $cycle['payment']['id'] : null,
            $status === 'approved' ? 'paid' : $status,
        );
    }

    /**
     * `x-signature: ts=…,v1=…` over `id:{data.id};request-id:{x-request-id};ts:{ts};`.
     *
     * Returns true (valid), false (present and wrong — refuse), or null (not
     * checkable: no secret stored, or the notification came through a
     * per-payment URL without one). Null is processed, because the body is only
     * ever used to know *which* payment to read back.
     */
    protected function mercadoPagoSignature(Request $request, string $dataId): ?bool
    {
        $secret = DirectBillingConfig::mpWebhookSecret();
        $header = (string) $request->header('x-signature', '');

        if ($secret === null || $header === '') {
            return null;
        }

        $ts = null;
        $hash = null;

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            match (trim((string) $key)) {
                'ts' => $ts = trim((string) $value),
                'v1' => $hash = trim((string) $value),
                default => null,
            };
        }

        if (! $ts || ! $hash) {
            return false;
        }

        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', strtolower($dataId), $request->header('x-request-id'), $ts);

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $hash);
    }

    // --- dLocal Go (every other market) ------------------------------------

    public function dlocalGo(Request $request)
    {
        $paymentId = (string) $request->input('payment_id', '');
        $valid = $this->dlocalGo->verifyWebhook($request);

        // Every transition of a payment sends byte-identical bodies, so no key
        // derived from them can deduplicate — each delivery is its own row, and
        // applying it twice is a no-op anyway.
        $event = WebhookEvent::create([
            'dedupe_key' => 'dlocalgo-billing:'.$paymentId.':'.Str::uuid(),
            'provider' => 'dlocalgo_billing',
            'event_type' => 'payment',
            'resource_id' => $paymentId ?: null,
            'signature_valid' => $valid,
            'payload' => $request->all(),
        ]);

        if (! $valid) {
            Log::warning('dLocal Go billing webhook with invalid signature', [
                'payment_id' => $paymentId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'invalid-signature'], 200);
        }

        if ($paymentId === '') {
            return response()->json(['status' => 'ignored'], 200);
        }

        try {
            $this->billing->applyPaymentUpdate($this->dlocalGo->getPayment($paymentId), BillingGateways::DLOCALGO);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('dLocal Go billing webhook processing failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
