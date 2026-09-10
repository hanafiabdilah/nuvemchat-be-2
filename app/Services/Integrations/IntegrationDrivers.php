<?php

namespace App\Services\Integrations;

use App\Enums\Integration\IntegrationProvider;
use App\Models\Integration;
use App\Services\Integrations\Payments\MercadoPagoGateway;
use App\Services\Integrations\Payments\OpenPixGateway;
use App\Services\Integrations\Payments\PaymentGateway;
use App\Services\Integrations\Pixels\GoogleAnalyticsDriver;
use App\Services\Integrations\Pixels\MetaPixelDriver;
use App\Services\Integrations\Pixels\PixelDriver;
use InvalidArgumentException;

/**
 * Provider → the class that speaks its dialect. The one place a new provider
 * is wired in on the server, besides its case on IntegrationProvider.
 */
final class IntegrationDrivers
{
    public static function for(Integration $integration): IntegrationDriver
    {
        return match ($integration->provider) {
            IntegrationProvider::OpenPix => new OpenPixGateway($integration),
            IntegrationProvider::MercadoPago => new MercadoPagoGateway($integration),
            IntegrationProvider::MetaPixel => new MetaPixelDriver($integration),
            IntegrationProvider::GoogleAnalytics => new GoogleAnalyticsDriver($integration),
        };
    }

    public static function payment(Integration $integration): PaymentGateway
    {
        $driver = self::for($integration);

        if (! $driver instanceof PaymentGateway) {
            throw new InvalidArgumentException("Integration {$integration->id} is not a payment gateway.");
        }

        return $driver;
    }

    public static function pixel(Integration $integration): PixelDriver
    {
        $driver = self::for($integration);

        if (! $driver instanceof PixelDriver) {
            throw new InvalidArgumentException("Integration {$integration->id} is not a pixel.");
        }

        return $driver;
    }
}
