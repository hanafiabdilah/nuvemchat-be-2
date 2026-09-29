<?php

namespace App\Enums\Billing;

use Illuminate\Support\Facades\Log;

/**
 * Which road a new charge takes (env `PAYMENT_METHOD`).
 *
 * `payment_service` is the group's own service, which owns every gateway
 * account and routes on its own. `direct` is Pingly talking to a gateway
 * itself: Mercado Pago for Brazil, dLocal Go for every other market — kept so
 * billing keeps working while the service is still being built behind it.
 *
 * ⚠️ This decides what is *created*, never what is *read*. An invoice or a
 * stored card remembers the gateway it was made on (`invoices.gateway`,
 * `subscriptions.gateway`), so flipping the variable does not orphan a charge
 * that is already open or a card that renews next week.
 */
enum BillingRoute: string
{
    case PaymentService = 'payment_service';
    case Direct = 'direct';

    public static function current(): self
    {
        $value = strtolower(trim((string) config('services.billing.payment_method')));

        $route = self::tryFrom($value);

        if ($route === null) {
            // A typo must not silently send money somewhere nobody chose — but
            // it also must not stop every checkout. The road that existed
            // before this setting did is the one nobody has to be told about.
            if ($value !== '') {
                Log::warning('Unknown PAYMENT_METHOD, falling back to payment_service', ['value' => $value]);
            }

            return self::PaymentService;
        }

        return $route;
    }
}
