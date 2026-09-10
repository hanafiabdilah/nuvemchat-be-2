<?php

namespace App\Services\Integrations;

/**
 * A provider where the webhook is configured on the account, not per request.
 *
 * OpenPix is one: it calls whatever URL the account registered, for every
 * charge. Mercado Pago is not — each payment carries its own notification URL —
 * so it has nothing to register and does not implement this.
 *
 * Registration is best-effort by design. A key that works but whose webhook
 * could not be registered (the provider validates the URL by calling it, which
 * a local development box cannot answer) still charges customers; the payments
 * are then confirmed by the polling sweep instead of in real time, and the
 * Integrations page says so.
 */
interface ManagesWebhooks
{
    /**
     * @return array<string, mixed> what to remember in `meta` to undo it later
     */
    public function registerWebhook(string $url, string $secret): array;

    /**
     * @param  array<string, mixed>  $meta  what registerWebhook() returned
     */
    public function unregisterWebhook(array $meta): void;
}
