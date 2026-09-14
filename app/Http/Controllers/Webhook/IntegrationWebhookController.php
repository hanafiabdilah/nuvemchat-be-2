<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Integration\IntegrationCategory;
use App\Enums\Integration\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\FlowInvoice;
use App\Models\FlowPayment;
use App\Models\Integration;
use App\Services\Flow\FlowInvoiceService;
use App\Services\Flow\FlowPaymentService;
use App\Services\Integrations\IntegrationDrivers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A workspace's payment gateway or nota fiscal platform telling us something
 * moved.
 *
 * The URL identifies the account (a random token per integration); the body is
 * only a pointer. Nothing in it is trusted — the charge or the invoice is read
 * back from the provider with the workspace's own key before anything changes —
 * so a forged delivery costs one GET and can never mark anything paid or issued.
 *
 * Handled inline rather than queued, for the reason SMS codes are: somebody is
 * sitting in the chat waiting for the bot to notice.
 */
class IntegrationWebhookController extends Controller
{
    public function __construct(
        private readonly FlowPaymentService $payments,
        private readonly FlowInvoiceService $invoices,
    ) {}

    public function handle(Request $request, string $provider, string $token): JsonResponse
    {
        $providerCase = IntegrationProvider::tryFrom($provider);

        $integration = $providerCase === null ? null : Integration::query()
            ->where('webhook_token', $token)
            ->where('provider', $providerCase->value)
            ->first();

        if ($integration === null || ! $integration->category()->receivesWebhooks()) {
            // Acknowledged, not refused. A deleted integration's webhook can
            // stay registered at the provider (a revoked key cannot remove
            // it), and a non-2xx earns hours of retries for something nobody
            // can act on.
            Log::info('Integration webhook for an unknown integration, ignored', ['provider' => $provider]);

            return response()->json(['ok' => true]);
        }

        try {
            if ($integration->category() === IntegrationCategory::Invoice) {
                $this->invoices($request, $integration);
            } else {
                $this->payments($request, $integration);
            }
        } catch (\Throwable $e) {
            // A 500 earns a retry, which is what a provider we could not read
            // back from (or a database hiccup) deserves: the payment or the
            // authorization is real and still has to be noticed.
            Log::error('Integration webhook could not be processed', [
                'integration_id' => $integration->id,
                'provider' => $integration->provider->value,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not process the notification.'], 500);
        }

        return response()->json(['ok' => true]);
    }

    private function payments(Request $request, Integration $integration): void
    {
        foreach (IntegrationDrivers::payment($integration)->webhookReferences($request) as $reference) {
            $payment = FlowPayment::query()
                ->where('integration_id', $integration->id)
                ->where('reference', $reference)
                ->first();

            if ($payment !== null) {
                $this->payments->refresh($payment);
            }
        }
    }

    private function invoices(Request $request, Integration $integration): void
    {
        $references = IntegrationDrivers::invoice($integration)->webhookReferences($request);

        if ($references === []) {
            return;
        }

        // Ours when the provider echoes the integration id back, the
        // provider's own id when all it sends is its invoice — usually both,
        // for the same row, which is read back once rather than twice.
        $invoices = FlowInvoice::query()
            ->where('integration_id', $integration->id)
            ->where(fn ($query) => $query->whereIn('reference', $references)->orWhereIn('provider_invoice_id', $references))
            ->get();

        foreach ($invoices as $invoice) {
            $this->invoices->refresh($invoice);
        }
    }
}
