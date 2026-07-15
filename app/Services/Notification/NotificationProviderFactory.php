<?php

namespace App\Services\Notification;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\Providers\PinglyNotificationProvider;
use App\Services\Notification\Providers\ProxyBrNotificationProvider;
use App\Services\Notification\Providers\WApiNotificationProvider;
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
        'wapi' => WApiNotificationProvider::class,
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
