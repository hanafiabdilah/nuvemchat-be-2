<?php

namespace App\Services\Billing\Gateways;

use App\Enums\Billing\BillingRoute;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\Gateways\Direct\DLocalGoBillingGateway;
use App\Services\Billing\Gateways\Direct\MercadoPagoBillingGateway;
use App\Services\Billing\PaymentService\PaymentServiceClient;

/**
 * Who takes a given charge.
 *
 * Two questions that look alike and are not:
 *
 *  - **What do we create next?** Decided by PAYMENT_METHOD and the workspace's
 *    market — `forTenant()`. Payment service: always the service. Direct:
 *    Mercado Pago for Brazil, dLocal Go for every other market.
 *  - **Who do we ask about something that exists?** Decided by the row itself
 *    — `forInvoice()` / `forSubscription()`. A card stored at the payment
 *    service keeps renewing there after the env is flipped to direct, and an
 *    open Pix made through Mercado Pago is still read from Mercado Pago after
 *    it is flipped back.
 *
 * Null in a `gateway` column is the payment service: every row from before this
 * choice existed went there.
 */
class BillingGateways
{
    public const PAYMENT_SERVICE = 'payment_service';

    public const MERCADOPAGO = 'mercadopago';

    public const DLOCALGO = 'dlocalgo';

    /** The market that direct billing sends to Mercado Pago. */
    public const MERCADOPAGO_MARKET = 'BR';

    public function forTenant(?Tenant $tenant): BillingGateway
    {
        if (BillingRoute::current() === BillingRoute::PaymentService) {
            return $this->named(self::PAYMENT_SERVICE);
        }

        $market = strtoupper((string) ($tenant?->market_code ?: self::MERCADOPAGO_MARKET));

        return $this->named($market === self::MERCADOPAGO_MARKET ? self::MERCADOPAGO : self::DLOCALGO);
    }

    public function forInvoice(Invoice $invoice): BillingGateway
    {
        return $this->named($invoice->gateway ?: self::PAYMENT_SERVICE);
    }

    public function forSubscription(Subscription $subscription): BillingGateway
    {
        return $this->named($subscription->gateway ?: self::PAYMENT_SERVICE);
    }

    public function named(string $name): BillingGateway
    {
        return match ($name) {
            self::MERCADOPAGO => app(MercadoPagoBillingGateway::class),
            self::DLOCALGO => app(DLocalGoBillingGateway::class),
            default => app(PaymentServiceClient::class),
        };
    }

    public function mercadoPago(): MercadoPagoBillingGateway
    {
        return app(MercadoPagoBillingGateway::class);
    }

    public function dlocalGo(): DLocalGoBillingGateway
    {
        return app(DLocalGoBillingGateway::class);
    }
}
