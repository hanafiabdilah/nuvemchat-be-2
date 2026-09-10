<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Integration\IntegrationCategory;
use App\Enums\Integration\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\FlowPayment;
use App\Models\Integration;
use App\Services\Flow\FlowPaymentService;
use App\Services\Integrations\IntegrationDrivers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A workspace's payment gateway telling us a charge moved.
 *
 * The URL identifies the account (a random token per integration); the body is
 * only a pointer. Nothing in it is trusted — the charge is read back from the
 * gateway with the workspace's own key before anything changes — so a forged
 * delivery costs one GET and can never mark anything paid.
 *
 * Handled inline rather than queued, for the reason SMS codes are: somebody is
 * sitting in the chat waiting for the bot to notice they paid.
 */
class IntegrationWebhookController extends Controller
{
    public function __construct(
        private readonly FlowPaymentService $payments,
    ) {}

    public function handle(Request $request, string $provider, string $token): JsonResponse
    {
        $providerCase = IntegrationProvider::tryFrom($provider);

        $integration = $providerCase === null ? null : Integration::query()
            ->where('webhook_token', $token)
            ->where('provider', $providerCase->value)
            ->first();

        if ($integration === null || $integration->category() !== IntegrationCategory::Payment) {
            // Acknowledged, not refused. A deleted integration's webhook can
            // stay registered at the provider (a revoked key cannot remove
            // it), and a non-2xx earns hours of retries for something nobody
            // can act on.
            Log::info('Payment webhook for an unknown integration, ignored', ['provider' => $provider]);

            return response()->json(['ok' => true]);
        }

        try {
            $references = IntegrationDrivers::payment($integration)->webhookReferences($request);

            foreach ($references as $reference) {
                $payment = FlowPayment::query()
                    ->where('integration_id', $integration->id)
                    ->where('reference', $reference)
                    ->first();

                if ($payment !== null) {
                    $this->payments->refresh($payment);
                }
            }
        } catch (\Throwable $e) {
            // A 500 earns a retry, which is what a gateway we could not read
            // back from (or a database hiccup) deserves: the payment is real
            // and still has to be noticed.
            Log::error('Payment webhook could not be processed', [
                'integration_id' => $integration->id,
                'provider' => $integration->provider->value,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not process the notification.'], 500);
        }

        return response()->json(['ok' => true]);
    }
}
