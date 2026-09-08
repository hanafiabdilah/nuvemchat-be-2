<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Events from the group's payment service: a payment moved, or a stored
 * instrument stopped working.
 *
 * Three things this endpoint owes the sender, in order:
 *
 *  - **Verify before trusting.** The body is unauthenticated until the
 *    signature checks out, and it says money moved.
 *  - **Deduplicate on the event id.** It is stable across every retry and
 *    across an operator pressing resend, and the body is frozen when the event
 *    is first recorded — so the same id always carries the same content.
 *  - **Answer fast, 2xx.** Anything else schedules a retry: ten seconds, a
 *    minute, up to six hours, eight attempts over about ten hours. After that
 *    the delivery is abandoned, and `billing:reconcile` is the fallback.
 *
 * The body is thin by design — what changed plus an id — so nothing here needs
 * a second call to act on it.
 */
class PaymentServiceWebhookController extends Controller
{
    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected BillingService $billing,
    ) {}

    public function handle(Request $request)
    {
        $signatureValid = $this->verifier->verify($request);

        $eventId = (string) $request->input('id');
        $type = (string) $request->input('type');
        $data = (array) $request->input('data', []);

        // No id means nothing can be deduplicated on, and a body we cannot
        // recognise twice is a body we must not act on once.
        if ($eventId === '') {
            Log::warning('Payment webhook with no event id', ['type' => $type]);

            return response()->json(['status' => 'ignored'], 200);
        }

        if (WebhookEvent::where('dedupe_key', $eventId)->whereNotNull('processed_at')->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $event = WebhookEvent::updateOrCreate(
            ['dedupe_key' => $eventId],
            [
                'provider' => 'payment_service',
                'event_type' => $type,
                'resource_id' => $data['payment_id'] ?? $data['instrument_id'] ?? null,
                'signature_valid' => $signatureValid,
                'payload' => $request->all(),
            ],
        );

        if (! $signatureValid) {
            Log::warning('Payment webhook with invalid signature', ['event_id' => $eventId, 'type' => $type]);

            return response()->json(['status' => 'invalid-signature'], 200);
        }

        try {
            $this->process($type, $data);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Payment webhook processing failed', [
                'event_id' => $eventId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            // processed_at stays null so billing:reconcile can pick it up.
        }

        return response()->json(['status' => 'ok'], 200);
    }

    protected function process(string $type, array $data): void
    {
        match (true) {
            // `payment.` plus the new status. Branching on the status inside
            // the payload rather than on the event name keeps this in step with
            // the fetch path, which sees the same field.
            Str::startsWith($type, 'payment.') => $this->billing->applyPaymentUpdate($data),

            // Every instrument event says the same operational thing — stop
            // scheduling against this — so they share one handler. A product
            // that only listened to payment events would learn about a revoked
            // Pix consent on the day billing failed.
            Str::startsWith($type, 'instrument.') => $this->billing->applyInstrumentUpdate($data),

            default => null,
        };
    }
}
