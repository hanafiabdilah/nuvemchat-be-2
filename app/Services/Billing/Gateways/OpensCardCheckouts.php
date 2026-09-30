<?php

namespace App\Services\Billing\Gateways;

/**
 * A gateway whose card form is bound to a payment that already exists.
 *
 * The usual card flow tokenises first and charges second: the browser turns
 * the card into a token, and one `createPayment()` call spends it. dLocal Go's
 * SmartFields turn that around — the form cannot even be drawn until a payment
 * has been created, because the SDK is initialised with that payment's checkout
 * token. So a card becomes two calls: open (before the form renders) and
 * confirm (with the token the form produced).
 *
 * A capability rather than two more methods on BillingGateway, for the same
 * reason as the capability interfaces on the message handlers: every other
 * gateway would implement them as a throw, and a throw that reads like an
 * implementation is a place someone eventually calls.
 */
interface OpensCardCheckouts
{
    /**
     * Create the payment the card form is initialised with. Nothing is charged
     * yet: an abandoned form leaves a payment that expires on the gateway's
     * side and nothing on ours.
     *
     * @param  array<string, mixed>  $payload  amount, currency, order_reference, description, customer, return_url
     * @return array{payment: array<string, mixed>, checkout_token: string}
     */
    public function openCardCheckout(array $payload): array;

    /**
     * Spend the card token on the payment opened above.
     *
     * The normalized payment comes back with `instrument.id` set whenever the
     * card was accepted (or is being authenticated): that is what later cycles
     * are charged against, with nobody at a screen.
     *
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed> The normalized payment.
     */
    public function confirmCardCheckout(string $checkoutToken, string $cardToken, array $customer): array;
}
