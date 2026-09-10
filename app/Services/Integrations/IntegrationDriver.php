<?php

namespace App\Services\Integrations;

use App\Exceptions\UpstreamServiceException;

/**
 * The one thing every connected app can do: prove its credentials work.
 *
 * Called before an integration is saved, not after. An account that saved and
 * then fails the first time a bot tries to charge a customer is a trap — the
 * failure lands in front of that customer, at the worst moment, with nobody
 * from the workspace watching.
 */
interface IntegrationDriver
{
    /**
     * Call the provider with the stored credentials.
     *
     * @return array<string, mixed> facts worth showing on the card (account
     *                              name, environment) — never a secret
     *
     * @throws UpstreamServiceException in our words when the provider refuses
     */
    public function verify(): array;
}
