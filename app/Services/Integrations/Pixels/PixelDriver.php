<?php

namespace App\Services\Integrations\Pixels;

use App\Exceptions\UpstreamServiceException;
use App\Services\Integrations\IntegrationDriver;

/**
 * A tracking destination: take one conversion and report it.
 *
 * Fire-and-forget from the flow's point of view — the pixel node never waits
 * for this, and a failure never changes what the customer sees. It is
 * recorded on the integration, where somebody reviewing their ad numbers will
 * look.
 */
interface PixelDriver extends IntegrationDriver
{
    /**
     * @throws UpstreamServiceException in our words when the provider refuses
     */
    public function send(PixelEvent $event): void;
}
