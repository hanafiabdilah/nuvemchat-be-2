<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\MercadoPago\MercadoPagoClient;
use App\Services\Billing\MercadoPago\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected MercadoPagoClient $mp,
        protected BillingService $billing,
    ) {}

    /**
     * Receive MercadoPago notifications. Always return 200 quickly so MP stops
     * retrying; processing is idempotent via the webhook_events dedupe key.
     */
    public function handle(Request $request)
    {
        $type = $request->input('type') ?? $request->input('topic');
        $dataId = $request->input('data.id') ?? $request->query('data.id') ?? $request->input('id');
        $signatureValid = $this->verifier->verify($request);

        $dedupeKey = "{$type}:{$dataId}";

        // Idempotency: skip if we've already seen this notification.
        if (WebhookEvent::where('dedupe_key', $dedupeKey)->whereNotNull('processed_at')->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $event = WebhookEvent::updateOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'provider' => 'mercadopago',
                'event_type' => $type,
                'resource_id' => $dataId,
                'signature_valid' => $signatureValid,
                'payload' => $request->all(),
            ],
        );

        if (! $signatureValid) {
            Log::warning('MercadoPago webhook with invalid signature', ['dedupe' => $dedupeKey]);
            return response()->json(['status' => 'invalid-signature'], 200);
        }

        try {
            $this->process($type, (string) $dataId);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('MercadoPago webhook processing failed', [
                'dedupe' => $dedupeKey,
                'error' => $e->getMessage(),
            ]);
            // Leave processed_at null so a reconcile/retry can pick it up.
        }

        return response()->json(['status' => 'ok'], 200);
    }

    protected function process(?string $type, string $dataId): void
    {
        match ($type) {
            'payment' => $this->billing->applyPaymentUpdate($this->mp->getPayment($dataId)),
            'subscription_preapproval', 'preapproval' => $this->billing->reconcilePreapproval($this->mp->getPreapproval($dataId)),
            // Recurring auto-debit charge on a card subscription (renewals): extend the
            // period + record a paid invoice.
            'subscription_authorized_payment', 'authorized_payment' => $this->billing->recordRecurringPayment($this->mp->getAuthorizedPayment($dataId)),
            default => null, // ignore unrelated topics (merchant_order, etc.)
        };
    }
}
