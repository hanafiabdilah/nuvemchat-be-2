<?php

namespace App\Services\Notification;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\Providers\PinglyNotificationProvider;
use App\Services\Notification\Providers\ProxyBrNotificationProvider;
use InvalidArgumentException;

/**
 * Resolves the configured notification provider. To add a new transport, map its
 * key to the implementing class here — the rest of the pipeline is provider-agnostic.
 */
class NotificationProviderFactory
{
    /**
     * @var array<string, class-string<NotificationProvider>>
     */
    protected array $providers = [
        'pingly' => PinglyNotificationProvider::class,
        // Labelled "API Way (Directly)"; the key stays `proxybr` because it is
        // what the stored settings and the whatsapp_message_logs rows say.
        'proxybr' => ProxyBrNotificationProvider::class,
    ];

    /**
     * @param string|null $key Provider key; defaults to the configured provider.
     */
    public function make(?string $key = null): NotificationProvider
    {
        $key = $key ?? NotificationConfig::provider();

        $class = $this->providers[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown notification provider [{$key}].");
        }

        return app($class);
    }

    /**
     * Keys of all registered providers (for the configuration UI).
     *
     * @return array<int, string>
     */
    public function available(): array
    {
        return array_keys($this->providers);
    }
}
