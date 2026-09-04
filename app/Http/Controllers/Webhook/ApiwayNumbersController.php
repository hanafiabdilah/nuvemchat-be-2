<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use App\Services\VirtualNumbers\VirtualNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `sms.received` push from API Way — one webhook for the whole platform.
 *
 * API Way registers a single URL per account, and the platform has one account,
 * so every tenant's codes arrive here. Routing to the right workspace is
 * entirely local: `number_id` is the handle we stored when the number was
 * rented, and a payload naming a number nobody owns is logged, never invented
 * into a row.
 *
 * Handled inline rather than queued. The whole point of this push is that a
 * person is sitting in front of a verification form; a code parked behind a
 * queue worker arrives after the form has stopped accepting it.
 */
class ApiwayNumbersController extends Controller
{
    public function __construct(
        private readonly VirtualNumberService $numbers,
    ) {}

    public function handle(Request $request)
    {
        if (! $this->verify($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->header('X-ApiWay-Event', 'sms.received');

        if ($event !== 'sms.received') {
            // Unknown event types are acknowledged, not retried: API Way backs
            // off for hours on a non-2xx, and refusing something we simply do
            // not handle would stall the delivery of things we do.
            Log::info('Ignoring an unhandled API Way numbers webhook event', ['event' => $event]);

            return response()->json(['ok' => true]);
        }

        try {
            $this->numbers->ingestWebhook($request->all());
        } catch (\Throwable $e) {
            // A 500 here earns a retry with backoff, which is what we want for
            // a transient database failure — the code is still recoverable.
            Log::error('Failed to ingest an API Way SMS webhook', [
                'error' => $e->getMessage(),
                'number_id' => $request->input('number_id'),
            ]);

            return response()->json(['message' => 'Could not store the message.'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * HMAC-SHA256 over the raw body, keyed by the secret `PUT /numbers/webhook`
     * returned once.
     *
     * ⚠️ Unlike the Meta verifier, a missing secret rejects rather than waves
     * the request through. There is no legacy traffic to protect here — the
     * webhook only exists once it has been registered, and registering it is
     * what produces the secret — so "no secret" means the setup is wrong, not
     * that verification is optional. An unsigned body on this route can create
     * an OTP in somebody's dashboard.
     */
    private function verify(Request $request): bool
    {
        $secret = ApiwayNumbersConfig::webhookSecret();

        if (empty($secret)) {
            Log::error('API Way SMS webhook rejected: no signing secret stored');

            return false;
        }

        $header = (string) $request->header('X-ApiWay-Signature');

        if ($header === '' || ! str_starts_with($header, 'sha256=')) {
            Log::warning('API Way SMS webhook rejected: missing or malformed signature header');

            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, substr($header, strlen('sha256=')))) {
            Log::warning('API Way SMS webhook rejected: signature mismatch');

            return false;
        }

        return true;
    }
}
