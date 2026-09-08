<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentService\PaymentServiceClient;
use App\Services\Billing\PaymentService\PaymentServiceConfig;

/**
 * The payment service credential's own proof.
 *
 * Sits beside the settings it tests, for the same reason the ProxyBR catalog
 * probe does: an API key nobody has exercised is a key nobody knows is wrong
 * until a customer presses Pay.
 */
class AdminPaymentServiceController extends Controller
{
    public function __construct(
        protected PaymentServiceClient $payments,
    ) {}

    public function test()
    {
        try {
            $methods = $this->payments->paymentMethods('BRL');
        } catch (UpstreamServiceException $e) {
            // ⚠️ The operator gets the *raw* sentence, not the customer copy.
            // They are the person who fixes the integration, and "não foi
            // possível concluir" tells them nothing about which account is
            // suspended — the same split ApiwayPartnerException makes.
            return response()->json([
                'message' => $e->rawMessage ?: $e->getMessage(),
                'code' => $e->getErrorCode(),
                'ref' => $e->reference,
            ], $e->httpStatus);
        }

        $card = collect($methods)->firstWhere('method', 'card');

        return response()->json([
            'data' => [
                'base_url' => PaymentServiceConfig::baseUrl(),
                'provider' => PaymentServiceConfig::provider(),
                'methods' => $methods,
                /*
                  The line worth reading twice. Card subscriptions are only
                  possible when some active gateway can charge a stored card
                  with nobody at the screen; when this is false the tenant
                  checkout hides the card tile, and this is the only screen that
                  explains why.
                */
                'card_auto_renew' => (bool) ($card['merchant_initiated_cards'] ?? false),
            ],
        ]);
    }
}
